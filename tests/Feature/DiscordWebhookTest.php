<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Enums\Recruitment;
use App\Jobs\PostToDiscord;
use App\Models\Company;
use App\Models\User;
use App\Notifications\HubNotice;
use App\Support\DiscordWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Support\SessionKey;
use RuntimeException;
use Tests\Feature\Api\JobSyncTest;
use Tests\TestCase;

/** A company's Discord webhook (#4652). */
class DiscordWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEF-wxyz';

    /** @return array{0: Company, 1: User, 2: User, 3: User} company, owner, manager, driver */
    private function company(bool $withWebhook = true, string $name = 'Convoy Club', string $tag = 'CVY'): array
    {
        $company = Company::factory()->create(['name' => $name, 'tag' => $tag, 'recruitment' => Recruitment::Open]);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $driver = User::factory()->create(['name' => 'Dora Driver']);
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        $company->members()->create(['user_id' => $manager->id, 'role' => CompanyRole::Manager]);
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        if ($withWebhook) {
            $company->forceFill(['discord_webhook' => self::URL])->save();
        }

        return [$company->refresh(), $owner, $manager, $driver];
    }

    /** @return array<string, mixed> a recent, plausible job for the sync API */
    private function recentJob(string $id, array $overrides = []): array
    {
        return JobSyncTest::job([
            'id' => $id,
            'started_at' => now()->subHours(5)->toIso8601String(),
            'completed_at' => now()->subHour()->toIso8601String(),
            ...$overrides,
        ]);
    }

    private function sync(User $driver, array $jobs): void
    {
        $token = $driver->createToken('PT', ['sync'])->plainTextToken;
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => $jobs])->assertOk();
    }

    public function test_managers_save_a_webhook_that_is_stored_encrypted_and_never_shown_again(): void
    {
        [$company, , $manager] = $this->company(false);

        $this->actingAs($manager)->put(route('companies.discord.update', $company), ['webhook' => self::URL])
            ->assertRedirect(route('companies.edit', $company));

        $raw = DB::table('companies')->where('id', $company->id)->value('discord_webhook');
        $this->assertNotSame(self::URL, $raw);
        $this->assertStringNotContainsString('abcdefghijklmnop', (string) $raw);
        $this->assertSame(self::URL, $company->refresh()->discord_webhook);

        $page = $this->actingAs($manager)->get(route('companies.edit', $company))->assertOk();
        $page->assertInertia(fn ($p) => $p->where('discord.set', true)->where('discord.hint', '…wxyz')->where('discord.disabled_at', null));
        $this->assertStringNotContainsString('abcdefghijklmnop', (string) $page->getContent());
        $this->assertStringNotContainsString('abcdefghijklmnop', json_encode($company->toArray()) ?: '');
    }

    public function test_only_discord_webhook_urls_are_accepted(): void
    {
        [$company, $owner] = $this->company(false);

        foreach ([
            'https://example.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
            'http://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
            'https://discord.com.evil.test/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
            'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz?x=1',
            'https://discord.com/api/webhooks/123/abc',
        ] as $bad) {
            $this->actingAs($owner)->put(route('companies.discord.update', $company), ['webhook' => $bad])
                ->assertSessionHasErrors('webhook');
        }

        $this->actingAs($owner)->put(route('companies.discord.update', $company), [
            'webhook' => 'https://canary.discordapp.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
        ])->assertSessionHasNoErrors();
    }

    public function test_drivers_and_outsiders_cannot_touch_the_webhook(): void
    {
        [$company, , , $driver] = $this->company();
        $outsider = User::factory()->create();

        foreach ([$driver, $outsider] as $user) {
            $this->actingAs($user)->put(route('companies.discord.update', $company), ['webhook' => self::URL])->assertForbidden();
            $this->actingAs($user)->post(route('companies.discord.test', $company))->assertForbidden();
            $this->actingAs($user)->delete(route('companies.discord.destroy', $company))->assertForbidden();
        }
        $this->assertSame(self::URL, $company->refresh()->discord_webhook);
    }

    public function test_a_test_message_posts_at_once_and_turns_a_disabled_webhook_back_on(): void
    {
        [$company, $owner] = $this->company();
        $company->forceFill(['discord_webhook_disabled_at' => now(), 'discord_webhook_error' => 'x'])->save();
        Http::fake(['discord.com/*' => Http::response(['id' => '1'], 200)]);

        $this->actingAs($owner)->post(route('companies.discord.test', $company))->assertRedirect();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::URL.'?wait=true'
            && $r['embeds'][0]['title'] === 'ProjectTrucks Hub is connected'
            && $r['allowed_mentions'] === ['parse' => []]);
        $this->assertTrue($company->refresh()->discordActive());
        $this->assertNull($company->discord_webhook_error);
    }

    public function test_a_failed_test_message_says_why(): void
    {
        [$company, $owner] = $this->company();
        Http::fake(['discord.com/*' => Http::response(['message' => 'Unknown Webhook'], 404)]);

        $this->actingAs($owner)->post(route('companies.discord.test', $company));

        $toast = session(SessionKey::FLASH_DATA)['toast'] ?? [];
        $this->assertSame('error', $toast['type'] ?? null);
        $this->assertStringContainsString('doesn’t know this webhook', $toast['message'] ?? '');
    }

    public function test_removing_the_webhook_clears_it(): void
    {
        [$company, $owner] = $this->company();

        $this->actingAs($owner)->delete(route('companies.discord.destroy', $company))->assertRedirect();

        $this->assertNull($company->refresh()->discord_webhook);
    }

    public function test_a_synced_company_job_is_posted_once(): void
    {
        Queue::fake();
        [, , , $driver] = $this->company();

        $this->sync($driver, [$this->recentJob('a1b2c3d4-0000-4000-8000-000000000001')]);
        // A re-sync of the same job isn't news.
        $this->sync($driver, [$this->recentJob('a1b2c3d4-0000-4000-8000-000000000001')]);

        Queue::assertPushed(PostToDiscord::class, 1);
        Queue::assertPushed(PostToDiscord::class, function (PostToDiscord $job) {
            $embed = $job->payload['embeds'][0];

            return $embed['title'] === 'Calais → Paris'
                && $embed['description'] === 'Dora Driver delivered on time.'
                && $embed['fields'][0]['value'] === '291 km';
        });
    }

    public function test_old_flagged_and_unlistened_jobs_are_not_posted_and_a_backlog_is_capped(): void
    {
        Queue::fake();
        [, , , $driver] = $this->company();

        $this->sync($driver, [
            JobSyncTest::job(['id' => 'a1b2c3d4-0000-4000-8000-000000000010']), // 2026-11-14: over a day old
            $this->recentJob('a1b2c3d4-0000-4000-8000-000000000011', ['flagged' => true, 'flag_reason' => 'x']),
            $this->recentJob('a1b2c3d4-0000-4000-8000-000000000012', ['status' => 'CANCELLED']),
        ]);
        Queue::assertNotPushed(PostToDiscord::class);

        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = $this->recentJob('a1b2c3d4-0000-4000-8000-00000000002'.$i);
        }
        $this->sync($driver, $jobs);
        Queue::assertPushed(PostToDiscord::class, DiscordWebhook::JOBS_PER_BATCH);

        // A driver in a company without a webhook.
        [, , , $other] = $this->company(false, 'Quiet Co', 'QUI');
        $this->sync($other, [$this->recentJob('a1b2c3d4-0000-4000-8000-000000000030')]);
        Queue::assertPushed(PostToDiscord::class, DiscordWebhook::JOBS_PER_BATCH);
    }

    public function test_new_members_and_new_events_are_posted(): void
    {
        Queue::fake();
        [$company, $owner] = $this->company();
        $joiner = User::factory()->create(['name' => 'Jo Joiner']);

        $this->actingAs($joiner)->post(route('companies.join', $company))->assertRedirect();
        $this->actingAs($owner)->post(route('companies.events.store', $company), [
            'title' => 'Friday convoy',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'game' => 'ets2',
            'start_city' => 'Calais',
            'dest_city' => 'Duisburg',
        ])->assertSessionHasNoErrors();

        Queue::assertPushed(PostToDiscord::class, fn (PostToDiscord $j) => $j->payload['embeds'][0]['title'] === 'Jo Joiner joined Convoy Club');
        Queue::assertPushed(PostToDiscord::class, fn (PostToDiscord $j) => $j->payload['embeds'][0]['title'] === 'New event: Friday convoy'
            && str_starts_with($j->payload['embeds'][0]['fields'][0]['value'], '<t:'));
    }

    public function test_the_queued_post_reads_the_current_webhook(): void
    {
        [$company] = $this->company();
        Http::fake(['discord.com/*' => Http::response(['id' => '1'], 200)]);

        (new PostToDiscord($company->id, DiscordWebhook::payload(['title' => 'Hi'])))->handle();
        Http::assertSentCount(1);

        // Removed before the worker got to it: nothing is sent.
        $company->forceFill(['discord_webhook' => null])->save();
        (new PostToDiscord($company->id, DiscordWebhook::payload(['title' => 'Hi'])))->handle();
        Http::assertSentCount(1);
    }

    public function test_a_deleted_webhook_is_switched_off_and_the_staff_hear_about_it(): void
    {
        Notification::fake();
        [$company, $owner, $manager, $driver] = $this->company();
        Http::fake(['discord.com/*' => Http::response(['message' => 'Unknown Webhook'], 404)]);

        (new PostToDiscord($company->id, DiscordWebhook::payload(['title' => 'Hi'])))->handle();

        $this->assertFalse($company->refresh()->discordActive());
        $this->assertNotNull($company->discord_webhook, 'kept, so it can be tested again');
        Notification::assertSentTo([$owner, $manager], HubNotice::class, fn (HubNotice $n) => $n->notice === NoticeType::DiscordWebhookDisabled);
        Notification::assertNotSentTo($driver, HubNotice::class);

        // Switched off: later posts don't call Discord.
        (new PostToDiscord($company->id, DiscordWebhook::payload(['title' => 'Hi'])))->handle();
        Http::assertSentCount(1);
    }

    public function test_a_server_error_is_retried_and_a_final_failure_switches_it_off_without_leaking_the_url(): void
    {
        Notification::fake();
        [$company] = $this->company();
        Http::fake(['discord.com/*' => Http::response('oops', 500)]);
        $post = new PostToDiscord($company->id, DiscordWebhook::payload(['title' => 'Hi']));

        try {
            $post->handle();
            $this->fail('a 500 must throw, so the queue retries');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('abcdefghijklmnop', $e->getMessage());
        }
        $this->assertTrue($company->refresh()->discordActive());

        $post->failed(new RuntimeException('cURL error for '.self::URL));

        $company->refresh();
        $this->assertFalse($company->discordActive());
        $this->assertStringNotContainsString('abcdefghijklmnop', (string) $company->discord_webhook_error);
        $this->assertSame(4, $post->tries);
    }
}
