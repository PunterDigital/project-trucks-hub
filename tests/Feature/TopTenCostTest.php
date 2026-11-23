<?php

namespace Tests\Feature;

use App\Achievements\Progress;
use App\Admin\Moderation;
use App\Enums\CompanyRole;
use App\Enums\DeliveryStatus;
use App\Jobs\PostToDiscord;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\JobSyncTest;
use Tests\TestCase;

/**
 * What a job sync costs (#5732): "Top ten" reads each finished week's leaders from a cache
 * instead of two aggregates per week driven, and a backlog sync doesn't lazy-load the driver
 * and company per job. Query counts are printed with PAO_DISABLE=1 for the PR.
 */
class TopTenCostTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday; JobSyncTest::job() is from Sat 14 Nov, so it lands in last week.
        $this->now = CarbonImmutable::parse('2026-11-18 12:00', 'UTC');
        $this->travelTo($this->now);
    }

    private function job(User $user, CarbonImmutable $at, float $km, array $state = []): Delivery
    {
        return Delivery::factory()->for($user)->create([
            'distance_km' => $km,
            'status' => DeliveryStatus::Delivered,
            'started_at' => $at->subHours(5),
            'completed_at' => $at,
            ...$state,
        ]);
    }

    /** @return list<string> */
    private function keys(User $user): array
    {
        return $user->achievements()->pluck('achievement')->map(fn ($a) => $a->value)->sort()->values()->all();
    }

    /** Queries one sync of a single current-week job runs. */
    private function syncQueries(User $user, string $token, string $id): int
    {
        // The test client stays signed in as the first token's driver otherwise.
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [JobSyncTest::job([
            'id' => $id,
            'started_at' => $this->now->subHours(5)->toIso8601String(),
            'completed_at' => $this->now->subHour()->toIso8601String(),
        ])]])->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_a_sync_costs_the_same_however_many_weeks_the_driver_has_driven(): void
    {
        // Ten rivals drive 2,000 km every week of the last 20, so nobody else is ever top ten.
        $rivals = User::factory()->count(10)->create();
        foreach (range(1, 20) as $w) {
            foreach ($rivals as $rival) {
                $this->job($rival, $this->now->subWeeks($w), 2000);
            }
        }

        $counts = [];
        foreach ([2, 20] as $weeks) {
            $driver = User::factory()->create();
            foreach (range(1, $weeks) as $w) {
                // Both drivers already past every distance badge the next sync could bring, so
                // the second sync unlocks nothing and only the weeks differ.
                $this->job($driver, $this->now->subWeeks($w), $weeks === 2 ? 1500 : 300);
            }
            $token = $driver->createToken('PT', ['sync'])->plainTextToken;

            $first = $this->syncQueries($driver, $token, 'job-'.$weeks.'-a');
            $counts[$weeks] = $this->syncQueries($driver, $token, 'job-'.$weeks.'-b');
            fwrite(STDERR, sprintf("\n  %d weeks driven: first sync %d queries, next sync %d", $weeks, $first, $counts[$weeks]));

            $this->assertNotContains('weekly_top_10', $this->keys($driver));
        }

        // Constant in weeks driven: the week leaders come from one cache read.
        $this->assertSame($counts[2], $counts[20]);
        $this->assertLessThanOrEqual(40, $counts[20]);
    }

    public function test_top_ten_follows_a_moderation_change_in_a_past_week(): void
    {
        $week = $this->now->subWeek();
        $rivals = User::factory()->count(10)->create();
        $jobs = $rivals->map(fn (User $r, int $i) => $this->job($r, $week, 1000 + $i));
        $driver = User::factory()->create();
        $this->job($driver, $week, 900);

        Progress::refresh($driver, notify: false);
        $this->assertNotContains('weekly_top_10', $this->keys($driver));

        // Staff hide one rival's job: only nine drove further, so she was top ten.
        Moderation::hideJob($jobs[3], 'Cheated');
        Progress::refresh($driver, notify: false);
        $this->assertContains('weekly_top_10', $this->keys($driver));
        $this->assertSame($this->now->startOfWeek()->toDateTimeString(), $driver->achievements()->where('achievement', 'weekly_top_10')->first()->unlocked_at->toDateTimeString());

        // Unhidden again: she drops out on the next refresh.
        Moderation::unhideJob($jobs[3]->refresh(), 'Appeal upheld');
        Progress::refresh($driver, notify: false);
        $this->assertNotContains('weekly_top_10', $this->keys($driver));

        // A rival going private or being suspended takes them off the board too.
        $rivals[0]->forceFill(['profile_private' => true])->save();
        Progress::refresh($driver, notify: false);
        $this->assertContains('weekly_top_10', $this->keys($driver));
        $rivals[0]->forceFill(['profile_private' => false])->save();
        Progress::refresh($driver, notify: false);
        $this->assertNotContains('weekly_top_10', $this->keys($driver));
        Moderation::suspend($rivals[1], 'Spam');
        Progress::refresh($driver, notify: false);
        $this->assertContains('weekly_top_10', $this->keys($driver));
        Moderation::liftSuspension($rivals[1]->refresh(), 'Sorted');

        // A rival deleting a job of that week from the app, or all their jobs, counts as well.
        $token = $rivals[2]->createToken('PT', ['sync'])->plainTextToken;
        Progress::refresh($driver, notify: false);
        $this->assertNotContains('weekly_top_10', $this->keys($driver));
        $this->withToken($token)->deleteJson(route('api.jobs.destroy', $jobs[2]->client_id))->assertNoContent();
        Progress::refresh($driver, notify: false);
        $this->assertContains('weekly_top_10', $this->keys($driver));

        // A late sync of an old job puts someone ahead again.
        $late = User::factory()->create();
        $this->job($late, $week, 5000);
        Progress::refresh($driver, notify: false);
        $this->assertNotContains('weekly_top_10', $this->keys($driver));
    }

    public function test_a_backlog_sync_does_not_lazy_load_driver_and_company_per_job(): void
    {
        Queue::fake([PostToDiscord::class]);
        // The achievement bell notices the backlog unlocks load their own rows: not per job.
        Notification::fake();
        $company = Company::factory()->create();
        $company->forceFill(['discord_webhook' => 'https://discord.com/api/webhooks/123456/'.str_repeat('a', 68)])->save();
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Owner]);
        $token = $driver->createToken('PT', ['sync'])->plainTextToken;

        // 100 jobs from last week: none recent enough to post.
        $jobs = [];
        foreach (range(1, 100) as $i) {
            $at = $this->now->subDays(7)->subHours($i);
            $jobs[] = JobSyncTest::job([
                'id' => 'backlog-'.$i,
                'started_at' => $at->subHours(4)->toIso8601String(),
                'completed_at' => $at->toIso8601String(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => $jobs])->assertOk()->assertJsonPath('synced', 100);
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $lazy = $log->filter(fn (string $q) => preg_match('/^select \* from "(users|companies)" where "\1"\."id" in/', $q) === 1);
        fwrite(STDERR, sprintf("\n  100-job backlog sync: %d queries, %d user/company lazy loads", $log->count(), $lazy->count()));
        $this->assertCount(0, $lazy);
        Queue::assertNotPushed(PostToDiscord::class);
    }
}
