<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Http\Controllers\Settings\PrivacyController;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Following drivers and the home feed tabs (#4647). */
class FollowTest extends TestCase
{
    use RefreshDatabase;

    private function join(User $user, Company $company): void
    {
        $company->members()->create(['user_id' => $user->id, 'role' => CompanyRole::Driver]);
        $user->unsetRelation('membership');
    }

    /** @return list<string> */
    private function feedCities(User $viewer, ?string $feed = null): array
    {
        $response = $this->actingAs($viewer)->get(route('home', $feed === null ? [] : ['feed' => $feed]));
        $latest = $response->inertiaProps('latest');

        return array_values(array_map(fn (array $job) => (string) $job['source_city'], $latest));
    }

    public function test_a_driver_follows_and_unfollows_another(): void
    {
        $fan = User::factory()->create();
        $driver = User::factory()->create();

        $this->actingAs($fan)->post(route('drivers.follow', $driver))->assertRedirect();
        $this->actingAs($fan)->post(route('drivers.follow', $driver))->assertRedirect();
        $this->assertSame(1, DB::table('follows')->count());

        $this->actingAs($fan)->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page
            ->where('follow.followers', 1)
            ->where('follow.following', 0)
            ->where('follow.is_following', true));
        $this->actingAs($fan)->get(route('drivers.show', $fan))->assertInertia(fn ($page) => $page
            ->where('follow.following', 1)
            ->where('follow.is_following', false));

        $this->actingAs($fan)->delete(route('drivers.unfollow', $driver))->assertRedirect();
        $this->assertSame(0, DB::table('follows')->count());
    }

    public function test_you_cannot_follow_yourself_and_guests_cannot_follow(): void
    {
        $driver = User::factory()->create();

        $this->actingAs($driver)->post(route('drivers.follow', $driver))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->post(route('drivers.follow', $driver))->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('follows')->count());
    }

    public function test_a_private_driver_can_be_followed_only_by_company_mates(): void
    {
        $company = Company::factory()->create();
        $private = User::factory()->create(['profile_private' => true]);
        $mate = User::factory()->create();
        $stranger = User::factory()->create();
        $this->join($private, $company);
        $this->join($mate, $company);

        $this->actingAs($stranger)->post(route('drivers.follow', $private))->assertNotFound();
        $this->actingAs($mate)->post(route('drivers.follow', $private))->assertRedirect();

        $this->assertSame(1, $private->followerCount());
        $this->assertSame(1, $mate->followingCount());
    }

    public function test_a_follow_stops_showing_once_the_driver_is_out_of_sight(): void
    {
        $company = Company::factory()->create();
        $private = User::factory()->create(['profile_private' => true]);
        $mate = User::factory()->create();
        $this->join($private, $company);
        $this->join($mate, $company);
        $mate->following()->attach($private->id);
        Delivery::factory()->for($private)->create(['source_city' => 'Lyon', 'company_id' => $company->id]);

        $this->assertSame(['Lyon'], $this->feedCities($mate, 'following'));

        // The mate leaves: the follow is still stored, but shows and counts nowhere.
        $company->members()->where('user_id', $mate->id)->delete();
        $mate->unsetRelation('membership');

        $this->assertSame([], $this->feedCities($mate, 'following'));
        $this->assertSame(0, $mate->fresh()->followingCount());
        $this->assertSame(0, $private->fresh()->followerCount());
        $this->assertSame(1, DB::table('follows')->count());

        // ...and unfollowing still works.
        $this->actingAs($mate)->delete(route('drivers.unfollow', $private))->assertRedirect();
        $this->assertSame(0, DB::table('follows')->count());
    }

    public function test_home_feed_tabs_filter_and_are_remembered(): void
    {
        $company = Company::factory()->create();
        $viewer = User::factory()->create();
        $followed = User::factory()->create();
        $mate = User::factory()->create();
        $other = User::factory()->create();
        $this->join($viewer, $company);
        $this->join($mate, $company);
        $viewer->following()->attach($followed->id);

        Delivery::factory()->for($followed)->create(['source_city' => 'Calais', 'completed_at' => now()->subHours(3)]);
        Delivery::factory()->for($mate)->create(['source_city' => 'Oslo', 'company_id' => $company->id, 'completed_at' => now()->subHours(2)]);
        Delivery::factory()->for($other)->create(['source_city' => 'Madrid', 'completed_at' => now()->subHour()]);

        $this->assertSame(['Madrid', 'Oslo', 'Calais'], $this->feedCities($viewer));
        $this->assertSame(['Calais'], $this->feedCities($viewer, 'following'));
        $this->assertSame('following', $viewer->fresh()->home_feed);
        // Remembered: no ?feed= shows the last pick.
        $this->assertSame(['Calais'], $this->feedCities($viewer));
        $this->assertSame(['Oslo'], $this->feedCities($viewer, 'company'));

        $this->actingAs($viewer)->get(route('home'))->assertInertia(fn ($page) => $page
            ->where('feed', 'company')
            ->where('feeds', ['everyone', 'following', 'company']));

        // Junk is ignored and doesn't overwrite the choice.
        $this->assertSame(['Oslo'], $this->feedCities($viewer, 'nonsense'));
        $this->assertSame('company', $viewer->fresh()->home_feed);
    }

    public function test_without_a_company_there_is_no_company_tab_and_guests_get_no_tabs(): void
    {
        $viewer = User::factory()->create(['home_feed' => null]);
        $viewer->forceFill(['home_feed' => 'company'])->save();

        $this->actingAs($viewer)->get(route('home', ['feed' => 'company']))->assertInertia(fn ($page) => $page
            ->where('feed', 'everyone')
            ->where('feeds', ['everyone', 'following']));

        $this->app['auth']->forgetGuards();
        $this->get(route('home', ['feed' => 'following']))->assertInertia(fn ($page) => $page
            ->where('feed', 'everyone')
            ->where('feeds', []));
    }

    public function test_follows_are_in_the_privacy_export_and_go_with_the_account(): void
    {
        $fan = User::factory()->create();
        $driver = User::factory()->create(['username' => 'fred-followed']);
        $fan->following()->attach($driver->id);
        $driver->following()->attach($fan->id);

        $export = PrivacyController::exportFor($fan);
        $this->assertSame(['fred-followed'], array_column($export['following'], 'username'));
        $this->assertSame(1, $export['followers']);

        $driver->delete();
        $this->assertSame(0, DB::table('follows')->count());
    }
}
