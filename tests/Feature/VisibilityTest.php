<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_never_send_an_email_address(): void
    {
        $driver = User::factory()->create(['email' => 'secret-driver@example.com']);
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Owner]);
        $job = Delivery::factory()->for($driver)->create(['company_id' => $company->id]);
        LiveStatus::query()->create(['user_id' => $driver->id, 'cargo_name' => 'Apples', 'updated_at' => now()]);

        foreach ([
            route('home'),
            route('drivers.index'),
            route('drivers.show', $driver),
            route('jobs.show', $job),
            route('companies.show', $company),
            route('leaderboards'),
        ] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertStringNotContainsString('secret-driver@example.com', $response->getContent(), $url);
        }
    }

    public function test_the_shared_user_prop_has_only_safe_fields(): void
    {
        $user = User::factory()->create();
        Company::factory()->create()->members()->create(['user_id' => $user->id, 'role' => CompanyRole::Driver]);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('auth.user.username', $user->username)
            ->missing('auth.user.membership')
            ->missing('auth.user.password')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.steam_id'));
    }

    public function test_a_private_profile_shows_to_its_owner_and_company_only(): void
    {
        $private = User::factory()->create(['profile_private' => true]);
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $private->id, 'role' => CompanyRole::Driver]);
        $mate = User::factory()->create();
        $company->members()->create(['user_id' => $mate->id, 'role' => CompanyRole::Driver]);
        $job = Delivery::factory()->for($private)->create(['company_id' => $company->id]);

        $this->get(route('drivers.show', $private))->assertInertia(fn ($page) => $page->component('drivers/Private')->missing('jobs'));
        // Out of the public feed and the drivers list.
        $this->get(route('home'))->assertInertia(fn ($page) => $page->has('latest', 0));
        $this->get(route('drivers.index'))->assertInertia(fn ($page) => $page->has('drivers.data', 1));

        $this->actingAs(User::factory()->create())->get(route('drivers.show', $private))
            ->assertInertia(fn ($page) => $page->component('drivers/Private'));
        $this->actingAs(User::factory()->create())->get(route('jobs.show', $job))->assertNotFound();

        $this->actingAs($mate)->get(route('drivers.show', $private))->assertInertia(fn ($page) => $page->component('drivers/Show'));
        $this->actingAs($mate)->get(route('jobs.show', $job))->assertOk();
        $this->actingAs($private)->get(route('drivers.show', $private))->assertInertia(fn ($page) => $page->component('drivers/Show'));
    }

    public function test_a_hidden_job_shows_only_to_its_driver(): void
    {
        $driver = User::factory()->create();
        $job = Delivery::factory()->for($driver)->create(['hidden' => true]);

        $this->get(route('jobs.show', $job))->assertNotFound();
        $this->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page->has('jobs.data', 0));
        $this->actingAs($driver)->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page->has('jobs.data', 1));
        $this->actingAs($driver)->get(route('jobs.show', $job))->assertOk();
    }

    public function test_drivers_hide_and_show_their_own_jobs(): void
    {
        $driver = User::factory()->create();
        $job = Delivery::factory()->for($driver)->create();

        // The page must report the real flag (a `$this->hidden` slip once sent Eloquent's array).
        $this->actingAs($driver)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page->where('job.hidden', false));

        $this->actingAs(User::factory()->create())->patch(route('jobs.update', $job), ['hidden' => true])->assertForbidden();
        $this->actingAs($driver)->patch(route('jobs.update', $job), ['hidden' => true]);
        $this->assertTrue($job->fresh()->hidden);
        $this->actingAs($driver)->patch(route('jobs.update', $job), ['hidden' => false]);
        $this->assertFalse($job->fresh()->hidden);
    }

    public function test_the_private_switch_is_saved_from_profile_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'username' => 'New-Handle',
            'profile_private' => '1',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->profile_private);
        $this->assertSame('new-handle', $user->username);
    }

    public function test_reserved_and_malformed_usernames_are_refused(): void
    {
        $user = User::factory()->create();

        foreach (['admin', 'ab', 'has space', '-leading'] as $bad) {
            $this->actingAs($user)->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'username' => $bad,
            ])->assertSessionHasErrors('username');
        }
    }

    public function test_on_the_road_ignores_stale_statuses(): void
    {
        $driver = User::factory()->create();
        LiveStatus::query()->create(['user_id' => $driver->id, 'cargo_name' => 'Apples', 'updated_at' => now()->subMinutes(10)]);

        $this->get(route('home'))->assertInertia(fn ($page) => $page->has('onTheRoad', 0));
        $this->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page->where('live', null));
    }
}
