<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday, so "this week" started two days ago.
        $this->travelTo('2026-11-11 12:00:00');
    }

    public function test_drivers_rank_by_counted_distance(): void
    {
        $far = User::factory()->create(['name' => 'Far']);
        $near = User::factory()->create(['name' => 'Near']);
        Delivery::factory()->for($far)->create(['distance_km' => 900, 'completed_at' => now()->subDay()]);
        Delivery::factory()->for($near)->create(['distance_km' => 400, 'completed_at' => now()->subDay()]);
        // None of these count.
        Delivery::factory()->for($near)->create(['distance_km' => 5000, 'status' => DeliveryStatus::Cancelled, 'completed_at' => now()->subDay()]);
        Delivery::factory()->for($near)->create(['distance_km' => 5000, 'flagged' => true, 'completed_at' => now()->subDay()]);
        Delivery::factory()->for($near)->create(['distance_km' => 5000, 'hidden' => true, 'completed_at' => now()->subDay()]);

        $this->get(route('leaderboards', ['period' => 'week']))->assertInertia(fn ($page) => $page
            ->component('leaderboards/Index')
            ->has('rows', 2)
            ->where('rows.0.subject.name', 'Far')
            ->where('rows.0.distance_km', 900)
            ->where('rows.1.subject.name', 'Near')
            ->where('rows.1.deliveries', 1));
    }

    public function test_periods_cut_off_older_jobs(): void
    {
        $driver = User::factory()->create();
        Delivery::factory()->for($driver)->create(['distance_km' => 100, 'completed_at' => now()->subDay()]);
        // Last week (before Monday 9 Nov) but this month.
        Delivery::factory()->for($driver)->create(['distance_km' => 200, 'completed_at' => '2026-11-05 10:00:00']);
        // Last month.
        Delivery::factory()->for($driver)->create(['distance_km' => 400, 'completed_at' => '2026-10-20 10:00:00']);

        $this->get(route('leaderboards', ['period' => 'week']))->assertInertia(fn ($page) => $page->where('rows.0.distance_km', 100));
        $this->get(route('leaderboards', ['period' => 'month']))->assertInertia(fn ($page) => $page->where('rows.0.distance_km', 300));
        $this->get(route('leaderboards', ['period' => 'all']))->assertInertia(fn ($page) => $page->where('rows.0.distance_km', 700));
    }

    public function test_private_drivers_are_off_the_driver_board_but_count_for_their_company(): void
    {
        $company = Company::factory()->create();
        $private = User::factory()->create(['profile_private' => true]);
        Delivery::factory()->for($private)->create(['company_id' => $company->id, 'distance_km' => 300, 'completed_at' => now()->subDay()]);

        $this->get(route('leaderboards', ['period' => 'week']))->assertInertia(fn ($page) => $page->has('rows', 0));
        $this->get(route('leaderboards', ['period' => 'week', 'board' => 'companies']))->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.subject.slug', $company->slug)
            ->where('rows.0.distance_km', 300));
    }

    public function test_other_metrics(): void
    {
        $rich = User::factory()->create(['name' => 'Rich']);
        $busy = User::factory()->create(['name' => 'Busy']);
        Delivery::factory()->for($rich)->create(['income' => 90000, 'completed_at' => now()->subDay()]);
        Delivery::factory()->for($busy)->count(3)->create(['income' => 1000, 'completed_at' => now()->subDay()]);

        $this->get(route('leaderboards', ['metric' => 'income']))->assertInertia(fn ($page) => $page->where('rows.0.subject.name', 'Rich'));
        $this->get(route('leaderboards', ['metric' => 'deliveries']))->assertInertia(fn ($page) => $page->where('rows.0.subject.name', 'Busy'));
        // Unknown values fall back to the defaults.
        $this->get(route('leaderboards', ['metric' => 'speed', 'period' => 'decade', 'board' => 'x']))
            ->assertInertia(fn ($page) => $page->where('metric', 'distance')->where('period', 'week')->where('board', 'drivers'));
    }
}
