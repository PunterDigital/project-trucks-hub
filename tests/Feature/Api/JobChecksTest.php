<?php

namespace Tests\Feature\Api;

use App\Admin\Moderation;
use App\Models\AdminAuditLog;
use App\Models\Delivery;
use App\Models\User;
use App\Sync\JobChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The Hub's own anti-cheat checks on synced jobs (#4642). */
class JobChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 18:00:00', 'UTC'));
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('PT', ['sync'])->plainTextToken;
    }

    /** @param  list<array<string, mixed>>  $jobs */
    private function sync(array $jobs): TestResponse
    {
        return $this->withToken($this->token)->postJson(route('api.jobs.store'), ['jobs' => $jobs]);
    }

    private function stored(string $id = '7f1c6a8e-3c2b-4d9e-9a51-0c6f2b1e5d10'): Delivery
    {
        return Delivery::query()->where('client_id', $id)->sole();
    }

    public function test_a_plausible_job_passes_and_counts(): void
    {
        $this->sync([JobSyncTest::job()])->assertOk()->assertJsonPath('jobs.0.hub_flag_reason', null);

        $job = $this->stored();
        $this->assertNull($job->hub_flag_reason);
        $this->assertFalse($job->hub_held);
        $this->assertSame(1, Delivery::query()->counted()->count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badJobs(): array
    {
        return [
            'average speed over 150 km/h' => [
                ['distance_km' => 500, 'duration_sec' => 7200, 'game_duration_min' => 120, 'avg_speed_kmh' => 250, 'started_at' => '2026-11-14T12:00:00Z'],
                'Average speed over 150 km/h',
            ],
            'reported speed over 150 km/h' => [
                ['game_duration_min' => 240, 'avg_speed_kmh' => 160],
                'Average speed over 150 km/h; Distance and driving time don\'t match',
            ],
            'delivery under 1 km' => [
                ['distance_km' => 0.5, 'duration_sec' => 600, 'avg_speed_kmh' => 3, 'started_at' => '2026-11-14T13:50:00Z'],
                'Distance under 1 km',
            ],
            'reported speed not distance / time' => [
                ['duration_sec' => 7200, 'started_at' => '2026-11-14T12:00:00Z'],
                'Distance and driving time don\'t match',
            ],
            'driven longer than the job lasted' => [
                ['started_at' => '2026-11-14T12:00:00Z'],
                'Distance and driving time don\'t match',
            ],
            'delivered before it started' => [
                ['started_at' => '2026-11-14T15:00:00Z', 'duration_sec' => 0, 'avg_speed_kmh' => 0, 'distance_km' => 0.5],
                'Distance under 1 km; Distance and driving time don\'t match',
            ],
            'kilometres with no driving time' => [
                ['duration_sec' => 0, 'avg_speed_kmh' => 0],
                'Distance and driving time don\'t match',
            ],
            'kilometres with no game time' => [
                ['game_duration_min' => 0, 'avg_speed_kmh' => 0],
                'Distance and driving time don\'t match',
            ],
            'reported speed not distance / game time' => [
                ['game_duration_min' => 2400, 'avg_speed_kmh' => 72.8],
                'Distance and driving time don\'t match',
            ],
            'older app: faster than any time scale allows' => [
                ['distance_km' => 3500, 'avg_speed_kmh' => 3500, 'duration_sec' => 3600, 'started_at' => '2026-11-14T13:00:00Z', 'declared_income' => 100],
                'Average speed over 150 km/h',
            ],
            'newer app: game time is fine but the real time is too short' => [
                ['distance_km' => 3500, 'duration_sec' => 3600, 'game_duration_min' => 2880, 'avg_speed_kmh' => 72.9, 'started_at' => '2026-11-14T13:00:00Z', 'declared_income' => 100],
                'Average speed over 150 km/h',
            ],
            'income far above what the distance pays' => [
                ['declared_income' => 1_000_000],
                'Income too high for the distance',
            ],
            // #5227: the game's figure is the one that counts, so it's the one judged.
            'game revenue far above what the distance pays' => [
                ['declared_income' => 12450, 'game_revenue' => 1_000_000],
                'Income too high for the distance',
            ],
            'completed in the future' => [
                ['started_at' => '2026-11-15T08:00:00Z', 'completed_at' => '2026-11-15T12:00:00Z'],
                'Completed in the future',
            ],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('badJobs')]
    public function test_implausible_jobs_get_a_hub_flag(array $overrides, string $reason): void
    {
        $this->sync([JobSyncTest::job($overrides)])->assertOk()->assertJsonPath('jobs.0.hub_flag_reason', $reason);

        $job = $this->stored();
        $this->assertSame($reason, $job->hub_flag_reason);
        $this->assertFalse($job->hub_held);
        $this->assertTrue($job->isFlagged());
        $this->assertSame(0, Delivery::query()->counted()->count());
    }

    public function test_more_than_24_hours_driven_in_one_day_is_flagged(): void
    {
        // 20.8 h already driven earlier the same UTC day, in five 15000 s jobs.
        for ($i = 0; $i < 5; $i++) {
            Delivery::factory()->for($this->user)->create([
                'duration_sec' => 15000,
                'completed_at' => Carbon::parse('2026-11-14 04:00:00')->addMinutes($i),
            ]);
        }

        $this->sync([JobSyncTest::job()])->assertOk();
        $this->assertSame('More than 24 h driven in one day', $this->stored()->hub_flag_reason);

        // The day before doesn't count.
        $this->sync([JobSyncTest::job([
            'id' => 'yesterday',
            'started_at' => '2026-11-13T10:00:00Z',
            'completed_at' => '2026-11-13T14:00:00Z',
        ])])->assertOk();
        $this->assertNull($this->stored('yesterday')->hub_flag_reason);
    }

    public function test_more_than_six_jobs_in_an_hour_are_held_for_review(): void
    {
        $jobs = [];
        for ($i = 0; $i < 7; $i++) {
            $done = Carbon::parse('2026-11-14 16:00:00')->addMinutes($i * 9);
            $jobs[] = JobSyncTest::job([
                'id' => "quick-$i",
                'distance_km' => 10,
                'duration_sec' => 480,
                'avg_speed_kmh' => 75,
                'declared_income' => 900,
                'started_at' => $done->copy()->subSeconds(480)->toIso8601String(),
                'completed_at' => $done->toIso8601String(),
            ]);
        }

        $this->sync($jobs)->assertOk()
            ->assertJsonPath('jobs.5.hub_flag_reason', null)
            ->assertJsonPath('jobs.6.hub_flag_reason', 'Held for review: more than 6 jobs in an hour');

        $held = $this->stored('quick-6');
        $this->assertTrue($held->hub_held);
        $this->assertSame(6, Delivery::query()->counted()->count());

        // Cancels don't count towards the rate.
        Delivery::query()->delete();
        $jobs[0]['status'] = 'CANCELLED';
        $this->sync($jobs)->assertOk()->assertJsonPath('jobs.6.hub_flag_reason', null);
    }

    public function test_a_resync_never_clears_a_hub_flag(): void
    {
        $this->sync([JobSyncTest::job(['declared_income' => 1_000_000])])->assertOk();

        $this->sync([JobSyncTest::job()])->assertOk()
            ->assertJsonPath('jobs.0.hub_flag_reason', 'Income too high for the distance');

        $job = $this->stored();
        $this->assertSame(12450, $job->income);
        $this->assertSame('Income too high for the distance', $job->hub_flag_reason);
        $this->assertSame(0, Delivery::query()->counted()->count());
    }

    public function test_a_resync_can_add_a_hub_flag(): void
    {
        $this->sync([JobSyncTest::job()])->assertOk();
        $this->sync([JobSyncTest::job(['declared_income' => 1_000_000])])->assertOk();

        $this->assertSame('Income too high for the distance', $this->stored()->hub_flag_reason);
    }

    public function test_the_apps_own_flag_still_counts(): void
    {
        $this->sync([JobSyncTest::job(['flagged' => true, 'flag_reason' => 'Average speed over 150 km/h'])])->assertOk();

        $job = $this->stored();
        $this->assertNull($job->hub_flag_reason);
        $this->assertTrue($job->isFlagged());
        $this->assertSame(0, Delivery::query()->counted()->count());
    }

    /**
     * #4771: the game's odometer counts game kilometres (~19 per real km on roads), so a real
     * delivery is several hundred km per real hour. Algeciras → Rotterdam: 2,455 km in 2 h 27 min.
     */
    public function test_a_real_long_delivery_passes_with_its_game_time(): void
    {
        $this->sync([JobSyncTest::job([
            'distance_km' => 2455.2, 'duration_sec' => 8797, 'game_duration_min' => 2790, 'avg_speed_kmh' => 52.8,
            'declared_income' => 111284, 'started_at' => '2026-11-14T11:30:00Z',
        ])])->assertOk()->assertJsonPath('jobs.0.hub_flag_reason', null);

        $this->assertSame(2790, $this->stored()->game_duration_min);
        $this->assertSame(1, Delivery::query()->counted()->count());
    }

    public function test_an_older_app_job_over_1000_km_per_real_hour_is_accepted_and_passes(): void
    {
        // ProjectTrucks before 0.11 reports distance / real time and no game time. The Hub
        // used to refuse anything over 1,000 km/h, which failed the whole batch.
        $this->sync([JobSyncTest::job([
            'distance_km' => 2455.2, 'duration_sec' => 8797, 'avg_speed_kmh' => 1004.7,
            'declared_income' => 111284, 'started_at' => '2026-11-14T11:30:00Z',
        ])])->assertOk()->assertJsonPath('jobs.0.hub_flag_reason', null);

        $this->assertNull($this->stored()->game_duration_min);
    }

    public function test_speed_flags_from_the_old_check_are_cleared_once(): void
    {
        $old = fn (string $id, array $extra = []) => Delivery::factory()->for($this->user)->create([
            'client_id' => $id, 'distance_km' => 2455.2, 'duration_sec' => 8797, 'avg_speed_kmh' => 1004.7,
            'income' => 111284, 'started_at' => '2026-11-14 11:30:00', 'completed_at' => '2026-11-14 14:00:00',
            'status' => 'DELIVERED', 'hub_flag_reason' => 'Average speed over 150 km/h', ...$extra,
        ]);
        $wrong = $old('a');
        $stillBad = $old('b', ['income' => 5_000_000, 'hub_flag_reason' => 'Average speed over 150 km/h; Income too high for the distance']);
        $staff = $old('c', ['hub_flag_reason' => 'Flagged by staff: Average speed over 150 km/h', 'staff_reviewed' => true]);
        $other = $old('d', ['hub_flag_reason' => 'Completed in the future']);

        $this->assertSame(1, JobChecks::recheckSpeedFlags());

        $this->assertNull($wrong->fresh()?->hub_flag_reason);
        $this->assertSame('Income too high for the distance', $stillBad->fresh()?->hub_flag_reason);
        $this->assertSame('Flagged by staff: Average speed over 150 km/h', $staff->fresh()?->hub_flag_reason);
        $this->assertSame('Completed in the future', $other->fresh()?->hub_flag_reason);
    }

    public function test_cancelled_jobs_are_not_checked(): void
    {
        $this->sync([JobSyncTest::job(['status' => 'CANCELLED', 'distance_km' => 0.4, 'duration_sec' => 60, 'avg_speed_kmh' => 24])])
            ->assertOk();

        $this->assertNull($this->stored()->hub_flag_reason);
    }

    public function test_thresholds_come_from_config(): void
    {
        config(['anticheat.max_avg_speed_kmh' => 60]);

        $this->sync([JobSyncTest::job(['game_duration_min' => 240])])->assertOk();

        $this->assertSame('Average speed over 60 km/h', $this->stored()->hub_flag_reason);
    }

    public function test_the_job_page_shows_the_driver_why_it_waits_for_a_review(): void
    {
        $this->sync([JobSyncTest::job(['declared_income' => 1_000_000])])
            ->assertOk()
            ->assertJsonPath('jobs.0.review', 'pending');
        $job = $this->stored();

        $this->get(route('jobs.show', $job))->assertOk()->assertInertia(fn ($page) => $page
            ->component('jobs/Show')
            ->where('job.review', 'pending')
            ->where('job.review_reasons', ['Income too high for the distance'])
            ->where('job.review_time', 'Reviews usually take no more than 12 hours.')
            ->where('job.review_note', null));
    }

    private function approve(Delivery $job): void
    {
        $staff = User::factory()->create();
        $staff->forceFill(['is_staff' => true])->save();
        Moderation::approveJob($job, 'Checked the route', $staff);
    }

    /**
     * #5530: a driver got one plausible job approved, then re-synced the same id with huge
     * figures. The approval goes, the checks run again, and it stops counting.
     */
    public function test_a_resync_with_changed_figures_takes_the_approval_away(): void
    {
        $this->sync([JobSyncTest::job(['flagged' => true, 'flag_reason' => 'Teleported'])])->assertOk();
        $this->approve($this->stored());
        $this->assertSame(1, Delivery::query()->counted()->count());

        $this->sync([JobSyncTest::job(['distance_km' => 100_000, 'declared_income' => 100_000_000])])
            ->assertOk()
            ->assertJsonPath('jobs.0.review', 'pending');

        $job = $this->stored();
        $this->assertNull($job->review);
        $this->assertNull($job->reviewed_at);
        $this->assertFalse($job->staff_reviewed);
        $this->assertNotNull($job->hub_flag_reason, 'the checks ran again');
        $this->assertSame(0, Delivery::query()->counted()->count());

        $log = AdminAuditLog::query()->where('action', 'job.approval_reset')->sole();
        $this->assertSame($job->id, $log->target_id);
        $this->assertSame('approved', $log->before['review']);
        $this->assertContains('distance_km', $log->before['changed']);
        $this->assertSame('pending', $log->after['review']);
    }

    public function test_a_hub_flagged_job_is_pending_again_after_a_changed_resync(): void
    {
        $this->sync([JobSyncTest::job(['declared_income' => 1_000_000])])->assertOk();
        $this->approve($this->stored());
        $this->assertSame(1, Delivery::query()->counted()->count());

        $this->sync([JobSyncTest::job(['declared_income' => 1_000_001])])
            ->assertOk()
            ->assertJsonPath('jobs.0.review', 'pending');

        $this->assertSame('Income too high for the distance', $this->stored()->hub_flag_reason);
        $this->assertSame(0, Delivery::query()->counted()->count());
    }

    public function test_an_unchanged_resync_keeps_the_approval(): void
    {
        $this->sync([JobSyncTest::job(['flagged' => true, 'flag_reason' => 'Teleported'])])->assertOk();
        $this->approve($this->stored());

        $this->sync([JobSyncTest::job(['flagged' => true, 'flag_reason' => 'Teleported'])])
            ->assertOk()
            ->assertJsonPath('jobs.0.review', 'approved');

        $this->assertTrue($this->stored()->staff_reviewed);
        $this->assertSame(1, Delivery::query()->counted()->count());
        $this->assertFalse(AdminAuditLog::query()->where('action', 'job.approval_reset')->exists());
    }

    public function test_a_resync_never_lifts_a_rejection(): void
    {
        $this->sync([JobSyncTest::job()])->assertOk();
        $staff = User::factory()->create();
        Moderation::rejectJob($this->stored(), 'Teleported', $staff);

        $this->sync([JobSyncTest::job(['distance_km' => 290])])
            ->assertOk()
            ->assertJsonPath('jobs.0.review', 'rejected');

        $this->assertSame(0, Delivery::query()->counted()->count());
    }
}
