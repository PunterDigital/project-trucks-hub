<?php

namespace Tests\Feature\Admin;

use App\Admin\QueueStats;
use App\Filament\Widgets\FailedJobsTable;
use App\Filament\Widgets\JobRuntimesTable;
use App\Filament\Widgets\QueueOverview;
use App\Filament\Widgets\QueueThroughputChart;
use App\Filament\Widgets\QueueWaitingTable;
use App\Models\AdminAuditLog;
use App\Models\FailedJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\QueueProbeJob;
use Tests\TestCase;

class QueuePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 14:20:00', 'UTC'));
    }

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();
    }

    public function test_the_worker_counts_processed_and_failed_jobs_per_hour(): void
    {
        QueueProbeJob::dispatch()->onConnection('database');
        QueueProbeJob::dispatch()->onConnection('database');
        QueueProbeJob::dispatch(fail: true)->onConnection('database');

        $this->work();
        $this->work();
        $this->work();

        $row = DB::table('queue_job_stats')->first();
        $this->assertSame(1, DB::table('queue_job_stats')->count());
        $this->assertSame(QueueProbeJob::class, $row->job_class);
        $this->assertSame('default', $row->queue);
        $this->assertSame('2026-11-18 14:00:00', substr((string) $row->hour, 0, 19));
        $this->assertSame(2, (int) $row->processed);
        $this->assertSame(1, (int) $row->failed);

        $this->assertSame(['processed' => 2, 'failed' => 1], QueueStats::lastDay());
        $this->assertSame(1, FailedJob::query()->count());
        $this->assertStringStartsWith('RuntimeException: Probe failed on purpose', FailedJob::query()->firstOrFail()->exception_summary);
        $this->assertStringNotContainsString('Stack trace', FailedJob::query()->firstOrFail()->exception_summary);

        $runtimes = QueueStats::runtimes();
        $this->assertSame(QueueProbeJob::class, $runtimes[0]['job_class']);
        $this->assertSame(3, $runtimes[0]['runs']);
        $this->assertSame(1, $runtimes[0]['failed']);
    }

    public function test_sync_jobs_are_not_counted(): void
    {
        QueueProbeJob::dispatch()->onConnection('sync');

        $this->assertSame(0, DB::table('queue_job_stats')->count());
    }

    public function test_hourly_has_one_slot_per_hour(): void
    {
        DB::table('queue_job_stats')->insert([
            ['hour' => '2026-11-18 14:00:00', 'job_class' => 'A', 'queue' => 'default', 'processed' => 3, 'failed' => 1, 'total_ms' => 40],
            ['hour' => '2026-11-18 14:00:00', 'job_class' => 'B', 'queue' => 'default', 'processed' => 2, 'failed' => 0, 'total_ms' => 10],
            ['hour' => '2026-11-18 12:00:00', 'job_class' => 'A', 'queue' => 'default', 'processed' => 1, 'failed' => 0, 'total_ms' => 5],
            ['hour' => '2026-11-15 12:00:00', 'job_class' => 'A', 'queue' => 'default', 'processed' => 9, 'failed' => 9, 'total_ms' => 5],
        ]);

        $hourly = QueueStats::hourly(3);

        $this->assertSame(['2026-11-18 12:00', '2026-11-18 13:00', '2026-11-18 14:00'], $hourly['hours']);
        $this->assertSame([1, 0, 5], $hourly['processed']);
        $this->assertSame([0, 0, 1], $hourly['failed']);
        // Last 7 days, busiest first: A ran 4 + 1 + 18 times in 50 ms, B twice in 10 ms.
        $this->assertSame([
            ['job_class' => 'A', 'runs' => 23, 'failed' => 10, 'avg_ms' => 2],
            ['job_class' => 'B', 'runs' => 2, 'failed' => 0, 'avg_ms' => 5],
        ], QueueStats::runtimes(7));
    }

    public function test_waiting_jobs_and_the_worker_warning(): void
    {
        QueueProbeJob::dispatch()->onConnection('database');
        $this->assertFalse(QueueStats::workerLooksDown());

        $this->travel(6)->minutes();
        $waiting = QueueStats::waiting();

        $this->assertSame('default', $waiting[0]['queue']);
        $this->assertSame(1, $waiting[0]['waiting']);
        $this->assertSame(360, $waiting[0]['oldest_seconds']);
        $this->assertTrue(QueueStats::workerLooksDown());

        // A job delayed into the future isn't overdue.
        DB::table('jobs')->update(['available_at' => now()->addMinute()->getTimestamp()]);
        $this->assertFalse(QueueStats::workerLooksDown());
    }

    public function test_staff_see_the_widgets(): void
    {
        $this->actingAs($this->staff());
        QueueProbeJob::dispatch()->onConnection('database');
        $this->travel(10)->minutes();

        Livewire::test(QueueOverview::class)->assertSee('Looks down')->assertSee('Failed jobs');
        Livewire::test(QueueThroughputChart::class)->assertSee('Jobs per hour, last 48 hours (UTC)');
        Livewire::test(QueueWaitingTable::class)->assertSee('default');
        Livewire::test(JobRuntimesTable::class)->assertSee('No queued job has run in the last 7 days');
    }

    public function test_staff_retry_and_forget_failed_jobs_with_audit_rows(): void
    {
        $this->actingAs($this->staff());
        QueueProbeJob::dispatch(fail: true)->onConnection('database');
        QueueProbeJob::dispatch(fail: true)->onConnection('database');
        $this->work();
        $this->work();
        [$first, $second] = FailedJob::query()->orderBy('id')->get()->all();

        Livewire::test(FailedJobsTable::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertSee('Probe failed on purpose')
            ->callAction(TestAction::make('retry')->table($first))
            ->assertNotified('Job back on the queue');

        $this->assertModelMissing($first);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertTrue(AdminAuditLog::query()->where(['action' => 'queue.job_retried', 'target_id' => $first->id])->exists());

        Livewire::test(FailedJobsTable::class)
            ->callAction(TestAction::make('forget')->table($second))
            ->assertNotified('Failed job forgotten');

        $this->assertModelMissing($second);
        $this->assertSame(1, DB::table('jobs')->count());
        $log = AdminAuditLog::query()->where('action', 'queue.job_forgotten')->firstOrFail();
        $this->assertSame(QueueProbeJob::class, $log->target_label);
    }
}
