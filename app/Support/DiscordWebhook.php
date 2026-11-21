<?php

namespace App\Support;

use App\Enums\CompanyRole;
use App\Enums\DeliveryStatus;
use App\Jobs\PostToDiscord;
use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\CompanyPost;
use App\Models\CompanyRank;
use App\Models\Delivery;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * A company's Discord webhook (#4652). Owners and managers paste a webhook URL on the company's
 * edit page; counted company jobs, new members and new events then post an embed there.
 *
 * Posts go through the queue (`PostToDiscord`, retried a few times). When Discord keeps
 * refusing, the webhook is switched off and the owner and managers get a bell notice. Only
 * Discord's own webhook URLs are accepted, so the Hub never posts anywhere else.
 */
class DiscordWebhook
{
    public const URL_PATTERN = '#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d{5,25}/[A-Za-z0-9_-]{20,100}$#';

    /** Embed colour: the Hub's amber. */
    private const COLOUR = 0xF59E0B;

    /** A first sync can bring hundreds of old jobs: only recent ones are posted, a few per sync batch. */
    public const JOB_MAX_AGE_HOURS = 24;

    public const JOBS_PER_BATCH = 3;

    /** A counted job the driver just synced, if their company listens. True when it was posted. */
    public static function jobDelivered(Delivery $job): bool
    {
        $job->loadMissing('user', 'company');
        $company = $job->company;

        if ($company === null || ! $company->discordActive()
            || ! in_array($job->status->value, DeliveryStatus::counted(), true)
            || ! $job->isCleared() || $job->getAttribute('hidden') || $job->staff_hidden
            || $job->user->isSuspended()
            || $job->completed_at->lt(now()->subHours(self::JOB_MAX_AGE_HOURS))) {
            return false;
        }

        $fields = [
            ['name' => __('Distance'), 'value' => number_format(round((float) $job->distance_km)).' km', 'inline' => true],
            ['name' => __('Cargo'), 'value' => self::field($job->cargo_name ?: '?'), 'inline' => true],
        ];
        if ($job->truckName() !== null) {
            $fields[] = ['name' => __('Truck'), 'value' => self::field((string) $job->truckName()), 'inline' => true];
        }

        self::post($company, [
            'title' => self::field(($job->source_city ?: '?').' → '.($job->dest_city ?: '?'), 256),
            'url' => route('jobs.show', $job),
            'description' => self::field(__(':name delivered :status.', [
                'name' => $job->user->name,
                'status' => $job->status === DeliveryStatus::Late ? __('late') : __('on time'),
            ]), 4096),
            'fields' => $fields,
            'timestamp' => $job->completed_at->toIso8601String(),
        ]);

        return true;
    }

    public static function memberJoined(Company $company, User $member): void
    {
        if (! $company->discordActive() || $member->isSuspended()) {
            return;
        }

        self::post($company, [
            'title' => self::field(__(':name joined :company', ['name' => $member->name, 'company' => $company->name]), 256),
            'url' => $member->profile_private ? route('companies.show', $company) : route('drivers.show', $member),
            'description' => __('Welcome aboard!'),
        ]);
    }

    /** Company ranks (#5223): a member reached a higher rank. */
    public static function rankPromoted(Company $company, User $member, CompanyRank $rank): void
    {
        if (! $company->discordActive() || $member->isSuspended()) {
            return;
        }

        self::post($company, [
            'title' => self::field(__(':name is now :rank', ['name' => $member->name, 'rank' => $rank->name]), 256),
            'url' => $member->profile_private ? route('companies.show', $company) : route('drivers.show', $member),
            'description' => self::field(__('Promoted at :company. Congratulations!', ['company' => $company->name]), 4096),
        ]);
    }

    public static function eventCreated(CompanyEvent $event): void
    {
        $event->loadMissing('company');
        $company = $event->company;
        if (! $company->discordActive()) {
            return;
        }

        self::post($company, [
            'title' => self::field(__('New event: :title', ['title' => $event->title]), 256),
            'url' => route('events.show', $event),
            'description' => self::field($event->start_city.' → '.$event->dest_city, 4096),
            'fields' => [
                // Discord shows <t:…> in each reader's own time zone.
                ['name' => __('Starts'), 'value' => '<t:'.$event->starts_at->getTimestamp().':F>', 'inline' => true],
                ['name' => __('Game'), 'value' => CompanyEvent::GAMES[$event->game] ?? $event->game, 'inline' => true],
            ],
        ]);
    }

    /** Company news (#5224): only when the author ticks "Also post to Discord". */
    public static function newsPosted(CompanyPost $post): void
    {
        $post->loadMissing('company');
        $company = $post->company;
        if (! $company->discordActive()) {
            return;
        }

        self::post($company, [
            'title' => self::field($post->title, 256),
            'url' => route('companies.show', $company),
            'description' => self::field($post->body, 4096),
            'timestamp' => $post->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Sends a test message right away (not queued), so the page can say whether it worked.
     * Success switches a disabled webhook back on. Returns an error message, or null.
     */
    public static function test(Company $company): ?string
    {
        if ($company->discord_webhook === null) {
            return __('Add a webhook URL first.');
        }

        try {
            $response = Http::timeout(10)->acceptJson()->post($company->discord_webhook.'?wait=true', self::payload([
                'title' => __('ProjectTrucks Hub is connected'),
                'url' => route('companies.show', $company),
                'description' => self::field(__('Jobs, new members and events from :company will show up here.', ['company' => $company->name]), 4096),
            ]));
        } catch (ConnectionException) {
            return __('Couldn’t reach Discord. Try again in a minute.');
        }

        if (! $response->successful()) {
            return in_array($response->status(), [401, 403, 404], true)
                ? __('Discord doesn’t know this webhook. It may have been deleted: create a new one and paste its URL.')
                : __('Discord answered with an error (:status). Try again in a minute.', ['status' => $response->status()]);
        }

        $company->forceFill(['discord_webhook_disabled_at' => null, 'discord_webhook_error' => null])->save();

        return null;
    }

    /** Switches the webhook off and tells the owner and managers. */
    public static function disable(Company $company, string $reason): void
    {
        if ($company->discord_webhook_disabled_at !== null) {
            return;
        }

        $company->forceFill(['discord_webhook_disabled_at' => now(), 'discord_webhook_error' => mb_substr($reason, 0, 255)])->save();

        $staff = User::query()
            ->whereIn('id', $company->members()->whereIn('role', [CompanyRole::Owner, CompanyRole::Manager])->select('user_id'))
            ->get();
        Notification::send($staff, HubNotice::discordWebhookDisabled($company));
    }

    /**
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    public static function payload(array $embed): array
    {
        return [
            'username' => 'ProjectTrucks Hub',
            // Never ping anyone, whatever a driver or company name contains.
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                ...$embed,
                'color' => self::COLOUR,
                'footer' => ['text' => 'ProjectTrucks Hub'],
            ]],
        ];
    }

    /** @param  array<string, mixed>  $embed */
    private static function post(Company $company, array $embed): void
    {
        PostToDiscord::dispatch($company->id, self::payload($embed))->afterCommit();
    }

    /** Discord's limits: 1,024 characters for a field value unless said otherwise. */
    private static function field(string $value, int $max = 1024): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
