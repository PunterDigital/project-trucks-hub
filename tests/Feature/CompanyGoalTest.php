<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyGoal;
use App\Models\Delivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Company goals (#4649). */
class CompanyGoalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-month, so "this month" and "last month" never depend on the real calendar.
        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    }

    /** @return array{Company, User, User, User} company, owner, manager, driver */
    private function company(): array
    {
        $company = Company::factory()->create(['name' => 'Goal Getters', 'tag' => 'GG']);
        $owner = User::factory()->create(['name' => 'Gina Goals']);
        $manager = User::factory()->create(['name' => 'Mia Manager']);
        $driver = User::factory()->create(['name' => 'Dan Driver']);
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        $company->members()->create(['user_id' => $manager->id, 'role' => CompanyRole::Manager]);
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        return [$company, $owner, $manager, $driver];
    }

    /** @param array<string, mixed> $attributes */
    private function job(User $user, Company $company, string $completed, array $attributes = []): Delivery
    {
        $at = CarbonImmutable::parse($completed, 'UTC');

        return Delivery::factory()->for($user)->create([
            'company_id' => $company->id,
            'distance_km' => 500,
            'income' => 20_000,
            'duration_sec' => 7 * 3600,
            'avg_speed_kmh' => 70,
            'started_at' => $at->subHours(7),
            'completed_at' => $at,
            ...$attributes,
        ]);
    }

    private function goal(Company $company, string $month, string $metric, int $target): void
    {
        $company->goals()->create(['month' => $month, 'metric' => $metric, 'target' => $target]);
    }

    public function test_owners_and_managers_set_this_months_goals(): void
    {
        [$company, $owner, $manager] = $this->company();

        $this->actingAs($owner)
            ->put(route('companies.goals.update', $company), ['distance_km' => '5000', 'deliveries' => '20', 'income' => ''])
            ->assertRedirect(route('companies.show', $company));

        $this->assertSame(
            ['deliveries' => 20, 'distance_km' => 5000],
            $company->goals()->where('month', '2026-11')->orderBy('metric')->pluck('target', 'metric')->all(),
        );

        // A manager changes one target and clears another; an empty box removes the goal.
        $this->actingAs($manager)
            ->put(route('companies.goals.update', $company), ['distance_km' => '8000', 'deliveries' => '', 'income' => '100000'])
            ->assertRedirect();

        $this->assertSame(
            ['distance_km' => 8000, 'income' => 100_000],
            $company->goals()->where('month', '2026-11')->orderBy('metric')->pluck('target', 'metric')->all(),
        );
    }

    public function test_drivers_and_outsiders_cannot_set_goals(): void
    {
        [$company, , , $driver] = $this->company();
        $outsider = User::factory()->create();

        $this->actingAs($driver)->put(route('companies.goals.update', $company), ['distance_km' => '5000'])->assertForbidden();
        $this->actingAs($outsider)->put(route('companies.goals.update', $company), ['distance_km' => '5000'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->put(route('companies.goals.update', $company), ['distance_km' => '5000'])->assertRedirect(route('login'));

        $this->assertSame(0, CompanyGoal::query()->count());
    }

    public function test_targets_are_validated(): void
    {
        [$company, $owner] = $this->company();

        $this->actingAs($owner)
            ->put(route('companies.goals.update', $company), ['distance_km' => '0', 'deliveries' => 'lots', 'income' => (string) (CompanyGoal::MAX_TARGET + 1)])
            ->assertSessionHasErrors(['distance_km', 'deliveries', 'income']);

        $this->assertSame(0, CompanyGoal::query()->count());
    }

    public function test_progress_counts_only_this_months_counted_jobs(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $this->goal($company, '2026-11', 'distance_km', 2000);
        $this->goal($company, '2026-11', 'deliveries', 4);

        $this->job($owner, $company, '2026-11-02 10:00');
        $this->job($manager, $company, '2026-11-10 10:00', ['distance_km' => 300]);
        // Not counted: last month, flagged, hidden, cancelled, another company.
        $this->job($owner, $company, '2026-10-31 23:59');
        $this->job($driver, $company, '2026-11-05 10:00', ['flagged' => true]);
        $this->job($driver, $company, '2026-11-06 10:00', ['hidden' => true]);
        $this->job($driver, $company, '2026-11-07 10:00', ['status' => 'CANCELLED']);
        $this->job($driver, Company::factory()->create(), '2026-11-08 10:00');

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (Assert $page) => $page
                ->where('goals.month', '2026-11')
                ->has('goals.current', 2)
                ->where('goals.current.0.metric', 'distance_km')
                ->where('goals.current.0.target', 2000)
                ->where('goals.current.0.progress', 800)
                ->where('goals.current.0.percent', 40)
                ->where('goals.current.1.metric', 'deliveries')
                ->where('goals.current.1.progress', 2)
                ->where('goals.current.1.percent', 50)
                // Contributions, biggest first.
                ->has('goals.current.0.contributors', 2)
                ->where('goals.current.0.contributors.0.driver.name', 'Gina Goals')
                ->where('goals.current.0.contributors.0.value', 500)
                ->where('goals.current.0.contributors.0.percent', 25)
                ->where('goals.current.0.contributors.1.driver.name', 'Mia Manager')
                ->where('goals.current.0.contributors.1.value', 300)
                ->has('goals.history', 0));
    }

    public function test_past_months_show_hit_or_missed(): void
    {
        [$company, $owner] = $this->company();
        $this->goal($company, '2026-10', 'distance_km', 400);
        $this->goal($company, '2026-10', 'deliveries', 5);
        $this->goal($company, '2026-09', 'income', 10_000);
        $this->job($owner, $company, '2026-10-15 10:00');
        $this->job($owner, $company, '2026-09-30 23:00');

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (Assert $page) => $page
                ->has('goals.current', 0)
                ->has('goals.history', 2)
                ->where('goals.history.0.month', '2026-10')
                ->where('goals.history.0.goals.0', ['metric' => 'distance_km', 'target' => 400, 'achieved' => 500, 'hit' => true])
                ->where('goals.history.0.goals.1', ['metric' => 'deliveries', 'target' => 5, 'achieved' => 1, 'hit' => false])
                ->where('goals.history.1.month', '2026-09')
                ->where('goals.history.1.goals.0', ['metric' => 'income', 'target' => 10_000, 'achieved' => 20_000, 'hit' => true]));
    }

    public function test_history_keeps_the_last_twelve_months(): void
    {
        [$company] = $this->company();

        for ($i = 1; $i <= 14; $i++) {
            $this->goal($company, CarbonImmutable::parse('2026-11-01', 'UTC')->subMonths($i)->format('Y-m'), 'deliveries', 1);
        }

        $this->get(route('companies.show', $company))
            ->assertInertia(fn (Assert $page) => $page
                ->has('goals.history', 12)
                ->where('goals.history.0.month', '2026-10')
                ->where('goals.history.11.month', '2025-11'));
    }

    public function test_private_drivers_and_former_members_in_contributions(): void
    {
        [$company, $owner, , $driver] = $this->company();
        $driver->forceFill(['profile_private' => true])->save();
        $leaver = User::factory()->create(['name' => 'Lee Leaver']);
        $this->goal($company, '2026-11', 'distance_km', 1000);
        $this->job($driver, $company, '2026-11-03 10:00', ['distance_km' => 200]);
        $this->job($leaver, $company, '2026-11-04 10:00', ['distance_km' => 100]);

        // A guest sees the private driver masked and the leaver's job as "Former members".
        $this->get(route('companies.show', $company))
            ->assertInertia(fn (Assert $page) => $page
                ->where('goals.current.0.progress', 300)
                ->where('goals.current.0.contributors.0.driver', ['name' => 'Private driver', 'username' => null, 'avatar' => null])
                ->where('goals.current.0.contributors.1.driver.name', 'Former members')
                ->where('goals.current.0.contributors.1.value', 100));

        // Company mates see the name.
        $this->actingAs($owner)->get(route('companies.show', $company))
            ->assertInertia(fn (Assert $page) => $page
                ->where('goals.current.0.contributors.0.driver.name', 'Dan Driver'));
    }

    public function test_goals_go_with_the_company(): void
    {
        [$company] = $this->company();
        $this->goal($company, '2026-11', 'deliveries', 3);

        $company->delete();

        $this->assertSame(0, CompanyGoal::query()->count());
    }
}
