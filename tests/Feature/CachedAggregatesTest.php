<?php

namespace Tests\Feature;

use App\Achievements\Progress;
use App\Admin\Housekeeping;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\User;
use App\Notifications\HubNotice;
use App\Support\Leaderboard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Aggregates that grew with every job (#5734): Home totals and leaderboards are cached for a
 * minute, the driver directory sorts by totals stored on users, the bell has its index, and
 * old read notices are pruned. Query counts are printed with PAO_DISABLE=1 for the PR.
 */
class CachedAggregatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00', 'UTC'));
    }

    private function job(User $user, float $km, array $state = []): Delivery
    {
        return Delivery::factory()->for($user)->create([
            'distance_km' => $km,
            'status' => DeliveryStatus::Delivered,
            'completed_at' => now()->subDays(2),
            ...$state,
        ]);
    }

    /** @return list<string> */
    private function queries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $log;
    }

    public function test_home_totals_and_boards_are_worked_out_once_a_minute(): void
    {
        $driver = User::factory()->create();
        $this->job($driver, 500);

        $totals = fn (array $log) => count(array_filter($log, fn (string $q) => str_contains($q, 'COUNT(*) as deliveries') && ! str_contains($q, 'group by')));
        $boards = fn (array $log) => count(array_filter($log, fn (string $q) => str_contains($q, 'group by "deliveries"."user_id"')));

        $first = $this->queries(fn () => $this->get('/')->assertInertia(fn (Assert $page) => $page->where('totals.distance_km', 500)));
        $this->job($driver, 250);
        $second = $this->queries(fn () => $this->get('/')->assertInertia(fn (Assert $page) => $page->where('totals.distance_km', 500)));
        fwrite(STDERR, sprintf("\n  Home: first view %d queries, the next within a minute %d", count($first), count($second)));

        $this->assertSame(1, $totals($first));
        $this->assertSame(1, $boards($first));
        $this->assertSame(0, $totals($second), 'the totals come from the cache');
        $this->assertSame(0, $boards($second), 'the top drivers come from the cache');

        // Over a minute later the new job shows.
        $this->travel(61)->seconds();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('totals.distance_km', 750));
    }

    public function test_the_leaderboard_page_reads_a_cached_board(): void
    {
        $driver = User::factory()->create(['name' => 'Lena Leader']);
        $this->job($driver, 800);

        $first = $this->queries(fn () => $this->get(route('leaderboards', ['period' => 'all']))->assertInertia(fn (Assert $page) => $page->where('rows.0.subject.name', 'Lena Leader')));
        $second = $this->queries(fn () => $this->get(route('leaderboards', ['period' => 'all']))->assertInertia(fn (Assert $page) => $page->where('rows.0.distance_km', 800)));
        fwrite(STDERR, sprintf("\n  /leaderboards?period=all: first %d queries, cached %d", count($first), count($second)));

        $this->assertLessThan(count($first), count($second));
        $this->assertSame([], array_filter($second, fn (string $q) => str_contains($q, 'from "deliveries"')));
        $this->assertSame(60, Leaderboard::CACHE_SECONDS);
    }

    public function test_the_directory_sorts_by_stored_totals_with_the_same_numbers(): void
    {
        $far = User::factory()->create(['name' => 'Far Away']);
        $near = User::factory()->create(['name' => 'Near By']);
        $none = User::factory()->create(['name' => 'No Jobs']);
        $this->job($far, 900);
        $this->job($far, 100, ['status' => DeliveryStatus::Late]);
        $this->job($near, 400);
        // None of these count.
        $this->job($near, 5000, ['status' => DeliveryStatus::Cancelled]);
        $this->job($near, 5000, ['flagged' => true]);
        $this->job($near, 5000, ['hidden' => true]);
        $this->job($near, 5000, ['hub_flag_reason' => 'Too fast']);
        foreach ([$far, $near, $none] as $u) {
            Progress::refresh($u, notify: false);
        }

        $this->get(route('drivers.index'))->assertInertia(fn (Assert $page) => $page
            ->where('drivers.data.0.name', 'Far Away')
            ->where('drivers.data.0.distance_km', 1000)
            ->where('drivers.data.0.deliveries', 2)
            ->where('drivers.data.1.name', 'Near By')
            ->where('drivers.data.1.distance_km', 400)
            ->where('drivers.data.1.deliveries', 1)
            ->where('drivers.data.2.name', 'No Jobs')
            ->where('drivers.data.2.distance_km', 0));

        // A constant number of queries, none over deliveries, however many jobs there are.
        $before = $this->queries(fn () => $this->get(route('drivers.index'))->assertOk());
        foreach (range(1, 30) as $i) {
            $this->job($near, 10);
        }
        $after = $this->queries(fn () => $this->get(route('drivers.index'))->assertOk());
        fwrite(STDERR, sprintf("\n  /drivers: %d queries with 9 jobs, %d with 39", count($before), count($after)));
        $this->assertSame(count($before), count($after));
        $this->assertSame([], array_filter($after, fn (string $q) => str_contains($q, '"deliveries"')));
    }

    public function test_hiding_a_job_updates_the_stored_totals(): void
    {
        $driver = User::factory()->create();
        $job = $this->job($driver, 700);
        Progress::refresh($driver, notify: false);
        $this->assertSame(700.0, $driver->refresh()->earned_km);

        $this->actingAs($driver)->patch(route('jobs.update', $job), ['hidden' => true])->assertRedirect();
        $this->assertSame(0.0, $driver->refresh()->earned_km);
        $this->assertSame(0, $driver->earned_jobs);
    }

    public function test_the_migration_backfills_the_totals(): void
    {
        $driver = User::factory()->create();
        $this->job($driver, 321.4);
        DB::table('users')->update(['earned_km' => 0, 'earned_jobs' => 0]);

        Artisan::call('hub:progress');

        $this->assertSame(321.4, $driver->refresh()->earned_km);
        $this->assertSame(1, $driver->earned_jobs);
    }

    public function test_the_bell_has_an_index_and_old_read_notices_are_pruned(): void
    {
        $index = collect(Schema::getIndexes('notifications'))->firstWhere('name', 'notifications_notifiable_created_index');
        $this->assertNotNull($index);
        $this->assertSame(['notifiable_type', 'notifiable_id', 'created_at'], $index['columns']);
        foreach (['notifications', 'users'] as $table) {
            foreach (Schema::getIndexes($table) as $i) {
                $this->assertLessThanOrEqual(64, strlen($i['name']), $i['name']);
            }
        }

        $driver = User::factory()->create();
        $notice = fn (int $daysAgo, bool $read) => tap($driver->notifications()->create([
            'id' => (string) str()->uuid(),
            'type' => HubNotice::class,
            'data' => ['type' => 'app_linked', 'message' => "{$daysAgo} days", 'url' => '/'],
            'read_at' => $read ? now()->subDays($daysAgo) : null,
        ]), fn ($n) => $n->forceFill(['created_at' => now()->subDays($daysAgo)])->save());

        $notice(120, true);   // pruned
        $notice(120, false);  // unread: kept
        $notice(30, true);    // recent: kept

        $this->assertSame(1, Housekeeping::pruneReadNotifications());
        $this->assertSame(['120 days', '30 days'], $driver->notifications()->get()->pluck('data.message')->sort()->values()->all());

        $this->artisan('hub:prune')->expectsOutput('Read notifications pruned: 0')->assertSuccessful();
    }
}
