<?php

namespace App\Notifications;

use App\Achievements\Achievement;
use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\CompanyPost;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Every Hub notification (#4643): one class, a `NoticeType` and the text worked out when it
 * is sent. It holds only strings, never models, so a queued email still sends after the
 * application, membership or company it was about has gone.
 *
 * The bell (database channel) is written at once on the `sync` connection; emails go through
 * the queue, which needs a queue worker on the server.
 */
class HubNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $mailLines extra paragraphs for the email only */
    public function __construct(
        public NoticeType $notice,
        public string $message,
        public string $url,
        public string $subject,
        public array $mailLines = [],
        public string $actionText = 'Open the Hub',
    ) {}

    public static function applicationReceived(Company $company, User $applicant, ?string $note): self
    {
        $lines = $note !== null && $note !== '' ? ['Their message: “'.$note.'”'] : [];

        return new self(
            NoticeType::ApplicationReceived,
            __(':name applied to join :company.', ['name' => $applicant->name, 'company' => $company->name]),
            route('companies.show', $company),
            __('New application to :company', ['company' => $company->name]),
            [...$lines, __('Accept or decline it on the company page.')],
            __('Review the application'),
        );
    }

    public static function applicationDecided(Company $company, bool $accepted): self
    {
        return new self(
            NoticeType::ApplicationDecided,
            $accepted
                ? __(':company accepted your application. Welcome aboard!', ['company' => $company->name])
                : __(':company declined your application.', ['company' => $company->name]),
            route('companies.show', $company),
            $accepted
                ? __('You joined :company', ['company' => $company->name])
                : __('Your application to :company', ['company' => $company->name]),
            $accepted ? [__('Your new jobs now count towards the company’s totals.')] : [],
            __('Open the company'),
        );
    }

    public static function memberJoined(Company $company, User $member): self
    {
        return new self(
            NoticeType::MemberJoined,
            __(':name joined :company.', ['name' => $member->name, 'company' => $company->name]),
            route('companies.show', $company),
            __(':name joined :company', ['name' => $member->name, 'company' => $company->name]),
        );
    }

    public static function removedFromCompany(Company $company): self
    {
        return new self(
            NoticeType::RemovedFromCompany,
            __('You were removed from :company.', ['company' => $company->name]),
            route('companies.index'),
            __('You were removed from :company', ['company' => $company->name]),
        );
    }

    public static function roleChanged(Company $company, CompanyRole $role): self
    {
        return new self(
            NoticeType::RoleChanged,
            __('You are now :role at :company.', ['role' => strtolower($role->label()), 'company' => $company->name]),
            route('companies.show', $company),
            __('Your role at :company changed', ['company' => $company->name]),
        );
    }

    /** Company ranks (#5223): bell only. */
    public static function rankPromoted(Company $company, string $rank): self
    {
        return new self(
            NoticeType::RankPromoted,
            __('You were promoted to :rank at :company.', ['rank' => $rank, 'company' => $company->name]),
            route('companies.show', $company),
            __('New rank at :company', ['company' => $company->name]),
        );
    }

    /**
     * Monthly activity requirement (#5225): to the owner and managers on the 1st, bell only.
     *
     * @param  list<string>  $names  the drivers who missed it
     */
    public static function activityMissed(Company $company, string $month, array $names): self
    {
        $shown = array_slice($names, 0, 10);
        $list = implode(', ', $shown).(count($names) > count($shown) ? ' '.__('and :n more', ['n' => count($names) - count($shown)]) : '');

        return new self(
            NoticeType::ActivityMissed,
            trans_choice(
                ":count driver missed :company's monthly minimum in :month: :names.|:count drivers missed :company's monthly minimum in :month: :names.",
                count($names),
                ['company' => $company->name, 'month' => $month, 'names' => $list],
            ),
            route('companies.show', $company),
            __('Monthly minimum at :company', ['company' => $company->name]),
        );
    }

    public static function ownershipHandedOver(Company $company, User $from): self
    {
        return new self(
            NoticeType::OwnershipHandedOver,
            __(':name handed :company over to you: you are now the owner.', ['name' => $from->name, 'company' => $company->name]),
            route('companies.show', $company),
            __('You now own :company', ['company' => $company->name]),
        );
    }

    public static function appLinked(string $clientName): self
    {
        return new self(
            NoticeType::AppLinked,
            __('“:app” was linked to your account and can now sync jobs.', ['app' => $clientName]),
            route('devices.index'),
            __('A ProjectTrucks app was linked to your account'),
            [__('If this wasn’t you, unlink it under Settings → Linked apps and change your password.')],
            __('See linked apps'),
        );
    }

    /** Bell only (#4645). */
    public static function achievementUnlocked(User $driver, Achievement $achievement): self
    {
        return new self(
            NoticeType::AchievementUnlocked,
            __('Achievement unlocked: :title. :description', ['title' => $achievement->title(), 'description' => $achievement->description()]),
            $driver->username !== null ? route('drivers.show', $driver).'#achievements' : route('dashboard'),
            __('Achievement unlocked: :title', ['title' => $achievement->title()]),
        );
    }

    /** Bell only (#4646). */
    public static function kudosReceived(Delivery $job, User $from): self
    {
        $route = $job->source_city.' → '.$job->dest_city;

        return new self(
            NoticeType::KudosReceived,
            __(':name gave kudos to your job :route.', ['name' => $from->name, 'route' => $route]),
            route('jobs.show', $job),
            __(':name gave kudos to your job', ['name' => $from->name]),
        );
    }

    /** Bell only (#4646). */
    public static function commentReceived(Delivery $job, User $from, string $body): self
    {
        $route = $job->source_city.' → '.$job->dest_city;

        return new self(
            NoticeType::CommentReceived,
            __(':name commented on your job :route: “:excerpt”', [
                'name' => $from->name,
                'route' => $route,
                'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $body) ?? $body, 80),
            ]),
            route('jobs.show', $job).'#comments',
            __(':name commented on your job', ['name' => $from->name]),
        );
    }

    /** Bell only (#4648), to everyone who answered going or maybe. */
    public static function eventCancelled(CompanyEvent $event): self
    {
        $event->loadMissing('company');

        return new self(
            NoticeType::EventCancelled,
            __(':company cancelled “:title” (:date UTC).', [
                'company' => $event->company->name,
                'title' => $event->title,
                'date' => $event->starts_at->utc()->format('j M H:i'),
            ]),
            route('events.show', $event),
            __('“:title” was cancelled', ['title' => $event->title]),
        );
    }

    /** Bell only (#4652), to the owner and managers: Discord refused the company's posts. */
    public static function discordWebhookDisabled(Company $company): self
    {
        return new self(
            NoticeType::DiscordWebhookDisabled,
            __('Posts to :company’s Discord kept failing, so the webhook is switched off. Check it and send a test message.', [
                'company' => $company->name,
            ]),
            route('companies.edit', $company).'#discord',
            __('Discord webhook switched off'),
        );
    }

    /** Company news (#5224): bell only, each member can switch it off. */
    public static function companyNews(CompanyPost $post): self
    {
        $post->loadMissing('company');

        return new self(
            NoticeType::CompanyNews,
            __(':company posted news: “:title”', ['company' => $post->company->name, 'title' => $post->title]),
            route('companies.show', $post->company),
            __('News from :company', ['company' => $post->company->name]),
        );
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        if ($notifiable->isSuspended() || ! $notifiable->wantsBell($this->notice)) {
            return [];
        }

        return $this->notice->emails() && $notifiable->wantsMail($this->notice)
            ? ['database', 'mail']
            : ['database'];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /** @return array{type: string, message: string, url: string} */
    public function toArray(User $notifiable): array
    {
        return ['type' => $this->notice->value, 'message' => $this->message, 'url' => $this->url];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->message);
        foreach ($this->mailLines as $line) {
            $mail->line($line);
        }

        return $mail
            ->action($this->actionText, $this->url)
            ->line(__('You can switch these emails off under Settings → Notifications.'));
    }
}
