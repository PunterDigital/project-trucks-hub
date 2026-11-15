<?php

namespace Tests\Feature;

use App\Achievements\Achievement;
use App\Achievements\Progress;
use App\Admin\Moderation;
use App\Enums\CompanyRole;
use App\Enums\DeliveryStatus;
use App\Enums\NoticeType;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Api\JobSyncTest;
use Tests\TestCase;

/** Driver levels and achievements (#4645). */
class AchievementsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function keys(User $user): array
    {
        return $user->achievements()->pluck('achievement')->map(fn ($a) => $a->value)->sort()->values()->all();
    }

    private function job(User $user, array $state = []): Delivery
    {
        return Delivery::factory()->for($user)->create([
            'distance_km' => 300,
            'cargo_mass_kg' => 10000,
            'status' => DeliveryStatus::Delivered,
            'completed_at' => CarbonImmutable::parse('2026-11-04 12:00', 'UTC'),
            ...$state,
        ]);
    }

    public function test_levels_follow_the_distance_curve_and_ranks_go_up_every_five_levels(): void
    {
        $this->assertSame(1, Progress::level(0));
        $this->assertSame(1, Progress::level(999.9));
        $this->assertSame(2, Progress::level(1000));
        $this->assertSame(3, Progress::level(2829));
        $this->assertSame(5, Progress::level(8000));
        $this->assertSame(10, Progress::level(27000));

        $this->assertSame('Rookie', Progress::rank(1));
        $this->assertSame('Rookie', Progress::rank(5));
        $this->assertSame('Driver', Progress::rank(6));
        $this->assertSame('Senior Driver', Progress::rank(11));
        $this->assertSame('Road Captain', Progress::rank(16));
        $this->assertSame('Veteran', Progress::rank(21));
        $this->assertSame('Legend', Progress::rank(26));
        $this->assertSame('Legend', Progress::rank(80));
    }

    public function test_achievements_and_level_come_from_counted_jobs_with_the_date_they_were_earned(): void
    {
        $user = User::factory()->create();
        $first = CarbonImmutable::parse('2026-11-01 10:00', 'UTC');
        for ($i = 0; $i < 10; $i++) {
            $this->job($user, ['completed_at' => $first->addDays($i), 'distance_km' => 120]);
        }
        $this->job($user, ['completed_at' => $first->addDays(20), 'distance_km' => 1600, 'cargo_mass_kg' => 22000, 'status' => DeliveryStatus::Late]);
        // None of these count.
        $this->job($user, ['distance_km' => 5000, 'flagged' => true]);
        $this->job($user, ['distance_km' => 5000, 'status' => DeliveryStatus::Cancelled]);
        $this->job($user, ['distance_km' => 5000, 'hub_flag_reason' => 'speed']);
        $this->job($user, ['distance_km' => 5000, 'cargo_mass_kg' => 30000, 'hidden' => true]);

        $new = Progress::refresh($user, notify: false);

        $this->assertCount(6, $new);
        $this->assertSame([
            'deliveries_10', 'distance_1k', 'first_delivery', 'heavy_load_20t', 'long_haul_1500',
        ], array_values(array_diff($this->keys($user), ['weekly_top_10'])));
        $this->assertSame(2, $user->refresh()->level); // 2,800 km; level 3 needs 2,828
        $this->assertContains('weekly_top_10', $this->keys($user)); // the only driver those weeks

        $dates = $user->achievements()->get()->mapWithKeys(fn ($a) => [$a->achievement->value => $a->unlocked_at->toDateString()]);
        $this->assertSame('2026-11-01', $dates['first_delivery']);
        $this->assertSame('2026-11-09', $dates['distance_1k']); // the 9th job reaches 1,080 km
        $this->assertSame('2026-11-10', $dates['deliveries_10']);
        $this->assertSame('2026-11-21', $dates['long_haul_1500']);

        // Idempotent: nothing new the second time, dates kept.
        $this->assertSame([], Progress::refresh($user, notify: false));
        $this->assertSame('2026-11-01', $user->achievements()->where('achievement', 'first_delivery')->first()->unlocked_at->toDateString());
    }

    public function test_twenty_on_time_deliveries_in_a_row(): void
    {
        $user = User::factory()->create();
        $at = CarbonImmutable::parse('2026-10-01 10:00', 'UTC');
        for ($i = 0; $i < 19; $i++) {
            $this->job($user, ['completed_at' => $at->addHours($i)]);
        }
        $this->job($user, ['completed_at' => $at->addHours(19), 'status' => DeliveryStatus::Late]);
        $this->job($user, ['completed_at' => $at->addHours(20)]);

        Progress::refresh($user, notify: false);
        $this->assertNotContains('on_time_streak_20', $this->keys($user));

        for ($i = 21; $i < 40; $i++) {
            $this->job($user, ['completed_at' => $at->addHours($i)]);
        }
        Progress::refresh($user, notify: false);
        $this->assertContains('on_time_streak_20', $this->keys($user));
    }

    public function test_a_job_that_stops_counting_takes_its_achievements_away_but_a_suspension_does_not(): void
    {
        $user = User::factory()->create();
        $job = $this->job($user, ['distance_km' => 1600]);
        Progress::refresh($user, notify: false);
        $this->assertContains('long_haul_1500', $this->keys($user));
        $this->assertSame(2, $user->refresh()->level);

        Moderation::suspend($user, 'Testing');
        Progress::refresh($user->refresh(), notify: false);
        $this->assertContains('long_haul_1500', $this->keys($user));

        Moderation::flagJob($job, 'Teleported');

        $this->assertSame([], $this->keys($user));
        $this->assertSame(1, $user->refresh()->level);
    }

    public function test_joining_a_company_is_kept_after_leaving(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post(route('companies.store'), [
            'name' => 'Team Haulage', 'tag' => 'TEAM', 'description' => null, 'recruitment' => 'open',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['joined_company'], $this->keys($owner));

        $driver = User::factory()->create();
        $company = Company::query()->where('tag', 'TEAM')->firstOrFail();
        $this->actingAs($driver)->post(route('companies.join', $company))->assertSessionHasNoErrors();
        $this->assertSame(['joined_company'], $this->keys($driver));

        $this->actingAs($driver)->post(route('companies.leave', $company));
        Progress::refresh($driver->refresh(), notify: false);
        $this->assertSame(['joined_company'], $this->keys($driver));
    }

    public function test_top_ten_of_a_finished_week_but_not_eleventh_and_not_the_current_week(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00', 'UTC')); // a Wednesday
        $lastWeek = CarbonImmutable::parse('2026-11-11 12:00', 'UTC');

        $rivals = User::factory()->count(10)->create();
        foreach ($rivals as $i => $rival) {
            $this->job($rival, ['completed_at' => $lastWeek, 'distance_km' => 1000 + $i]);
        }
        $private = User::factory()->create(['profile_private' => true]);
        $this->job($private, ['completed_at' => $lastWeek, 'distance_km' => 5000]);

        $eleventh = User::factory()->create();
        $this->job($eleventh, ['completed_at' => $lastWeek, 'distance_km' => 999]);
        $tenth = User::factory()->create();
        // Beats one rival; the private driver isn't on the board, so it doesn't push her out.
        $this->job($tenth, ['completed_at' => $lastWeek, 'distance_km' => 1000.5]);
        $current = User::factory()->create();
        $this->job($current, ['completed_at' => CarbonImmutable::parse('2026-11-17 09:00', 'UTC'), 'distance_km' => 9000]);

        Progress::refresh($eleventh, notify: false);
        Progress::refresh($tenth, notify: false);
        Progress::refresh($current, notify: false);

        $this->assertNotContains('weekly_top_10', $this->keys($eleventh));
        $this->assertContains('weekly_top_10', $this->keys($tenth));
        $this->assertSame('2026-11-16 00:00:00', $tenth->achievements()->where('achievement', 'weekly_top_10')->first()->unlocked_at->toDateTimeString());
        $this->assertNotContains('weekly_top_10', $this->keys($current));
        $this->assertNotContains('weekly_top_10', $this->keys($private->refresh()));
    }

    public function test_syncing_unlocks_and_rings_the_bell_once(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('PT', ['sync'])->plainTextToken;

        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [JobSyncTest::job()]])->assertOk();

        $this->assertSame(['first_delivery'], $this->keys($user));
        $notices = $user->notifications()->get();
        $this->assertCount(1, $notices);
        $this->assertSame(NoticeType::AchievementUnlocked->value, $notices[0]->data['type']);
        $this->assertStringContainsString('First delivery', $notices[0]->data['message']);
        $this->assertStringEndsWith('#achievements', $notices[0]->data['url']);

        // A re-sync of the same job unlocks nothing new.
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [JobSyncTest::job()]])->assertOk();
        $this->assertCount(1, $user->notifications()->get());
    }

    public function test_the_backfill_command_is_silent(): void
    {
        $user = User::factory()->create();
        $this->job($user, ['distance_km' => 1200]);

        Artisan::call('hub:progress');

        $this->assertSame(['distance_1k', 'first_delivery', 'weekly_top_10'], $this->keys($user));
        $this->assertSame(2, $user->refresh()->level);
        $this->assertCount(0, $user->notifications()->get());
    }

    public function test_profile_shows_level_and_every_achievement_and_cards_show_the_level(): void
    {
        $user = User::factory()->create(['username' => 'lena-level']);
        Company::factory()->create()->members()->create(['user_id' => $user->id, 'role' => CompanyRole::Owner]);
        $this->job($user, ['distance_km' => 1500]);
        Progress::refresh($user, notify: false);

        $this->get(route('drivers.show', $user))->assertInertia(fn ($page) => $page
            ->where('progress.level', 2)
            ->where('progress.rank', 'Rookie')
            ->where('progress.distance_km', 1500)
            ->where('driver.level', 2)
            ->has('progress.achievements', count(Achievement::cases()))
            ->where('progress.achievements.0.key', 'first_delivery')
            ->whereNot('progress.achievements.0.unlocked_at', null)
            ->where('progress.achievements.1.key', 'distance_1k')
            ->where('progress.achievements.2.unlocked_at', null));

        $this->get(route('leaderboards', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('rows.0.subject.level', 2)
            ->where('rows.0.subject.rank', 'Rookie'));
    }

    public function test_deleting_synced_jobs_resets_progress(): void
    {
        $user = User::factory()->create();
        $this->job($user, ['distance_km' => 1200]);
        Progress::refresh($user, notify: false);

        $this->actingAs($user)->delete(route('privacy-settings.jobs.destroy'));

        $this->assertSame([], $this->keys($user));
        $this->assertSame(1, $user->refresh()->level);
    }
}
