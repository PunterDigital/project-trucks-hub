<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\DeliveryStatus;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Charts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Stats charts on profiles and company pages (#4650). */
class ChartsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function job(User $driver, string $completedAt, float $km, array $extra = []): Delivery
    {
        return Delivery::factory()->for($driver)->create([
            'completed_at' => Carbon::parse($completedAt, 'UTC'),
            'started_at' => Carbon::parse($completedAt, 'UTC')->subHours(2),
            'distance_km' => $km,
            ...$extra,
        ]);
    }

    public function test_profile_distance_comes_as_quarter_hour_utc_buckets_of_counted_jobs(): void
    {
        $driver = User::factory()->create();
        $this->job($driver, '2026-11-14 23:46:10', 100);
        $this->job($driver, '2026-11-14 23:52:00', 50);   // same bucket (23:45)
        $this->job($driver, '2026-11-15 00:05:00', 25);
        $this->job($driver, '2026-11-13 10:00:00', 40, ['status' => DeliveryStatus::Cancelled]);
        $this->job($driver, '2026-11-13 10:00:00', 40, ['flagged' => true]);
        $this->job($driver, '2026-11-13 10:00:00', 40, ['hub_flag_reason' => 'speed']);
        $this->job($driver, '2026-11-13 10:00:00', 40, ['hidden' => true]);
        $this->job($driver, '2026-10-01 10:00:00', 999); // before the window

        $this->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page
            ->component('drivers/Show')
            ->where('charts.distance', [
                [Carbon::parse('2026-11-14 23:45:00', 'UTC')->getTimestamp(), 150],
                [Carbon::parse('2026-11-15 00:00:00', 'UTC')->getTimestamp(), 25],
            ]));
    }

    public function test_the_window_reaches_one_day_past_thirty_for_far_time_zones(): void
    {
        $driver = User::factory()->create();
        // 31 days ago in UTC is still within the last 30 days at UTC+14 late in the day.
        $this->job($driver, '2026-10-15 01:00:00', 10);
        $this->job($driver, '2026-10-13 23:00:00', 10);

        $points = Charts::distance($driver->deliveries()->getQuery());

        $this->assertSame([[Carbon::parse('2026-10-15 01:00:00', 'UTC')->getTimestamp(), 10.0]], $points);
    }

    public function test_profile_cargo_lists_the_top_eight_by_jobs(): void
    {
        $driver = User::factory()->create();
        foreach (range(1, 9) as $i) {
            foreach (range(1, $i) as $n) {
                $this->job($driver, '2026-11-01 10:00:00', 10, ['cargo_name' => 'Cargo '.$i]);
            }
        }
        $this->job($driver, '2026-11-01 10:00:00', 10, ['cargo_name' => 'Cargo 9', 'flagged' => true]);

        $this->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page
            ->has('charts.cargo', 8)
            ->where('charts.cargo.0', ['name' => 'Cargo 9', 'deliveries' => 9, 'distance_km' => 90])
            ->where('charts.cargo.7.name', 'Cargo 2'));
    }

    public function test_company_chart_stacks_the_top_five_members_and_sums_the_rest(): void
    {
        $company = Company::factory()->create();
        $drivers = [];
        foreach (range(1, 7) as $i) {
            $driver = User::factory()->create(['name' => 'Driver '.$i]);
            $company->members()->create(['user_id' => $driver->id, 'role' => $i === 1 ? CompanyRole::Owner : CompanyRole::Driver]);
            $this->job($driver, '2026-11-10 12:00:00', $i * 100, ['company_id' => $company->id]);
            $drivers[$i] = $driver;
        }
        // A job for another company doesn't count here.
        $this->job($drivers[1], '2026-11-10 12:00:00', 5000);

        $this->get(route('companies.show', $company))->assertInertia(fn ($page) => $page
            ->has('chart', 6)
            ->where('chart.0.name', 'Driver 7')
            ->where('chart.0.distance_km', 700)
            ->where('chart.4.name', 'Driver 3')
            ->where('chart.5.key', 'others')
            ->where('chart.5.name', 'Others')
            ->where('chart.5.distance_km', 300)
            ->where('chart.5.points', [[Carbon::parse('2026-11-10 12:00:00', 'UTC')->getTimestamp(), 300]]));
    }

    public function test_company_chart_names_private_drivers_only_to_people_who_can_see_them(): void
    {
        $company = Company::factory()->create();
        $private = User::factory()->create(['name' => 'Secret Sam', 'profile_private' => true]);
        $company->members()->create(['user_id' => $private->id, 'role' => CompanyRole::Owner]);
        $this->job($private, '2026-11-10 12:00:00', 100, ['company_id' => $company->id]);
        $mate = User::factory()->create();
        $company->members()->create(['user_id' => $mate->id, 'role' => CompanyRole::Driver]);

        $this->get(route('companies.show', $company))->assertInertia(fn ($page) => $page
            ->where('chart.0.name', 'Private driver')
            ->where('chart.0.username', null));

        $this->actingAs(User::factory()->create())->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->where('chart.0.name', 'Private driver'));

        $this->actingAs($mate)->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->where('chart.0.name', 'Secret Sam'));
    }

    public function test_suspended_drivers_are_left_out_of_the_company_chart(): void
    {
        $company = Company::factory()->create();
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Owner]);
        $this->job($driver, '2026-11-10 12:00:00', 100, ['company_id' => $company->id]);
        $driver->forceFill(['suspended_at' => now(), 'suspension_reason' => 'test'])->save();

        $this->get(route('companies.show', $company))->assertInertia(fn ($page) => $page->has('chart', 0));
    }

    public function test_empty_charts(): void
    {
        $driver = User::factory()->create();
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Owner]);

        $this->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page
            ->where('charts.distance', [])
            ->where('charts.cargo', []));
        $this->get(route('companies.show', $company))->assertInertia(fn ($page) => $page->where('chart', []));
    }
}
