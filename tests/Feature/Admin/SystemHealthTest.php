<?php

namespace Tests\Feature\Admin;

use App\Admin\DatabaseStats;
use App\Admin\SchedulerStatus;
use App\Filament\Widgets\DatabaseOverview;
use App\Filament\Widgets\DatabaseSizeChart;
use App\Filament\Widgets\DatabaseTables;
use App\Filament\Widgets\ScheduledTasks;
use App\Filament\Widgets\SchedulerHeartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 03:17:00', 'UTC'));
    }

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    private function task(string $command): Event
    {
        return new Event(app(CacheEventMutex::class), $command);
    }

    public function test_tables_list_every_table_with_its_rows(): void
    {
        User::factory()->count(3)->create();

        $users = collect(DatabaseStats::tables())->firstWhere('name', 'users');

        $this->assertNotNull($users);
        $this->assertSame(3, $users['rows']);
        $this->assertNotNull(collect(DatabaseStats::tables())->firstWhere('name', 'deliveries'));
        $this->assertGreaterThan(0, DatabaseStats::totalBytes());
    }

    public function test_the_connection_says_nothing_secret(): void
    {
        $info = DatabaseStats::connection();

        $this->assertSame(['driver', 'version', 'uptime_seconds', 'connections'], array_keys($info));
        $this->assertSame('sqlite', $info['driver']);
        $this->assertMatchesRegularExpression('/^3\.\d+/', (string) $info['version']);
    }

    public function test_a_snapshot_is_stored_once_a_day_unless_forced(): void
    {
        User::factory()->create();

        $this->assertTrue(DatabaseStats::snapshot());
        $this->assertFalse(DatabaseStats::snapshot());

        $this->assertSame(1, DB::table('db_table_snapshots')->where(['date' => '2026-11-18', 'table_name' => 'users'])->value('row_count'));
        $this->assertTrue(DB::table('db_table_snapshots')->where(['date' => '2026-11-18', 'table_name' => DatabaseStats::TOTAL])->exists());

        User::factory()->create();
        $this->assertTrue(DatabaseStats::snapshot(force: true));
        $this->assertSame(2, DB::table('db_table_snapshots')->where(['date' => '2026-11-18', 'table_name' => 'users'])->value('row_count'));

        $this->artisan('hub:db-snapshot')->expectsOutputToContain('already has a snapshot')->assertSuccessful();
    }

    public function test_growth_compares_with_the_snapshot_a_week_ago(): void
    {
        User::factory()->count(3)->create();
        DB::table('db_table_snapshots')->insert([
            'date' => '2026-11-10', 'table_name' => 'users', 'row_count' => 1, 'data_bytes' => 100, 'index_bytes' => null,
        ]);

        $users = collect(DatabaseStats::tablesWithGrowth())->firstWhere('name', 'users');

        // Sizes are known only where SQLite has dbstat (CI's may, Windows PHP's doesn't).
        if ($users['growth_unit'] === 'rows') {
            $this->assertSame(2, $users['growth_7']);
        } else {
            $this->assertSame($users['total_bytes'] - 100, $users['growth_7']);
        }
        $this->assertNull($users['growth_30']);
    }

    public function test_the_size_series_reads_the_total_rows(): void
    {
        DB::table('db_table_snapshots')->insert([
            ['date' => '2026-11-01', 'table_name' => DatabaseStats::TOTAL, 'row_count' => 9, 'data_bytes' => 2048],
            ['date' => '2026-11-17', 'table_name' => DatabaseStats::TOTAL, 'row_count' => 9, 'data_bytes' => 4096],
            ['date' => '2026-11-17', 'table_name' => 'users', 'row_count' => 9, 'data_bytes' => 1],
            ['date' => '2026-10-01', 'table_name' => DatabaseStats::TOTAL, 'row_count' => 9, 'data_bytes' => 1024],
        ]);

        $this->assertSame(['2026-11-01' => 2048, '2026-11-17' => 4096], DatabaseStats::totalSeries(30));
    }

    public function test_the_scheduler_is_late_until_it_beats(): void
    {
        $this->assertTrue(SchedulerStatus::isLate());
        $this->assertNull(SchedulerStatus::lastBeat());

        // Laravel only turns Symfony's command events into CommandStarting outside unit tests
        // (a real `php artisan schedule:run`), so dispatch it as the console kernel would.
        event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));
        $this->assertTrue(SchedulerStatus::isLate());

        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
        $this->assertFalse(SchedulerStatus::isLate());

        $this->travel(11)->minutes();
        $this->assertTrue(SchedulerStatus::isLate());
    }

    public function test_finished_and_failed_tasks_are_recorded_by_name(): void
    {
        $command = "'/usr/bin/php8.4' 'artisan' hub:progress --notify";
        event(new ScheduledTaskFinished($this->task($command)->weeklyOn(1, '00:15'), 1.234));
        event(new ScheduledTaskFailed($this->task("'php' 'artisan' hub:daily-stats"), new RuntimeException('boom'), 0.5));

        $runs = SchedulerStatus::runs();

        $this->assertSame(['hub:progress --notify', 'hub:daily-stats'], array_keys($runs));
        $this->assertTrue($runs['hub:progress --notify']['ok']);
        $this->assertSame('15 0 * * 1', $runs['hub:progress --notify']['schedule']);
        $this->assertSame(1.23, $runs['hub:progress --notify']['runtime']);
        $this->assertFalse($runs['hub:daily-stats']['ok']);
    }

    public function test_staff_see_the_database_widgets(): void
    {
        $this->actingAs($this->staff());
        DatabaseStats::snapshot();

        Livewire::test(DatabaseOverview::class)
            ->assertSee('Total size')
            ->assertSee('SQLite')
            ->assertDontSee((string) config('database.connections.sqlite.database'));
        Livewire::test(DatabaseSizeChart::class)->assertSee('Database size, last 30 days (MB)');
        Livewire::test(DatabaseTables::class)
            ->assertSee('deliveries')
            ->assertSee('daily_stats');
    }

    public function test_the_database_page_takes_todays_snapshot(): void
    {
        $this->actingAs($this->staff())->get('/admin/database')->assertOk()->assertSee('Database');

        $this->assertTrue(DB::table('db_table_snapshots')->where('date', '2026-11-18')->exists());
    }

    public function test_staff_see_the_scheduler_state(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(SchedulerHeartbeat::class)->assertSee('Never run here');
        Livewire::test(ScheduledTasks::class)->assertSee('No scheduled task has run on this server yet');

        SchedulerStatus::beat();
        event(new ScheduledTaskFinished($this->task("'php' 'artisan' hub:db-snapshot")->dailyAt('00:10'), 0.2));

        Livewire::test(SchedulerHeartbeat::class)->assertSee('Running');
        Livewire::test(ScheduledTasks::class)->assertSee('hub:db-snapshot')->assertSee('OK');
    }
}
