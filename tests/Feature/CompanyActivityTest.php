<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\DeliveryStatus;
use App\Enums\NoticeType;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Delivery;
use App\Models\User;
use App\Support\CompanyActivity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Monthly activity requirement and member activity view (#5225). */
class CompanyActivityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User, 3: User} company, owner, manager, driver */
    private function company(array $state = []): array
    {
        $company = Company::factory()->create(['name' => 'Active Haulage', 'tag' => 'ACT', 'recruitment' => Recruitment::Open, ...$state]);
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $manager = User::factory()->create(['name' => 'Mona Manager']);
        $driver = User::factory()->create(['name' => 'Dora Driver']);
        $joined = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
        $this->member($company, $owner, CompanyRole::Owner, $joined);
        $this->member($company, $manager, CompanyRole::Manager, $joined);
        $this->member($company, $driver, CompanyRole::Driver, $joined);

        return [$company->refresh(), $owner, $manager, $driver];
    }

    private function member(Company $company, User $user, CompanyRole $role, CarbonImmutable $joined): CompanyMember
    {
        $member = $company->members()->create(['user_id' => $user->id, 'role' => $role]);
        $member->forceFill(['created_at' => $joined])->save();

        return $member;
    }

    private function job(User $user, Company $company, float $km, string $completedAt, array $state = []): Delivery
    {
        $at = CarbonImmutable::parse($completedAt, 'UTC');

        return Delivery::factory()->for($user)->create([
            'company_id' => $company->id,
            'distance_km' => $km,
            'status' => DeliveryStatus::Delivered,
            'started_at' => $at->subHours(3),
            'completed_at' => $at,
            ...$state,
        ]);
    }

    public function test_owners_and_managers_set_the_minimum_and_drivers_cannot(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();

        $this->actingAs($manager)->put(route('companies.activity.update', $company), ['min_km' => 1000, 'min_deliveries' => ''])
            ->assertRedirect(route('companies.edit', $company));
        $company->refresh();
        $this->assertSame(1000, $company->activity_min_km);
        $this->assertNull($company->activity_min_deliveries);

        $this->actingAs($owner)->put(route('companies.activity.update', $company), ['min_km' => null, 'min_deliveries' => 5])
            ->assertRedirect();
        $company->refresh();
        $this->assertNull($company->activity_min_km);
        $this->assertSame(5, $company->activity_min_deliveries);

        $this->actingAs($driver)->put(route('companies.activity.update', $company), ['min_km' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create())->put(route('companies.activity.update', $company), ['min_km' => 1])->assertForbidden();

        $this->actingAs($owner)->from(route('companies.edit', $company))
            ->put(route('companies.activity.update', $company), ['min_km' => 0, 'min_deliveries' => CompanyActivity::MAX_DELIVERIES + 1])
            ->assertSessionHasErrors(['min_km', 'min_deliveries']);
        $this->assertSame(5, $company->refresh()->activity_min_deliveries);

        $this->actingAs($owner)->get(route('companies.edit', $company))
            ->assertInertia(fn (Assert $page) => $page->where('activity', ['min_km' => null, 'min_deliveries' => 5]));
    }

    public function test_the_month_boundary_is_utc_and_only_counted_jobs_for_the_company_count(): void
    {
        [$company, , , $driver] = $this->company(['activity_min_km' => 500]);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:00:30', 'UTC'));

        $this->job($driver, $company, 400, '2026-10-31 23:59:59');
        $this->job($driver, $company, 150, '2026-11-01 00:00:00');
        // Not counted: cancelled, hidden, or driven for another company.
        $this->job($driver, $company, 900, '2026-10-20 12:00:00', ['status' => DeliveryStatus::Cancelled]);
        $this->job($driver, $company, 900, '2026-10-20 12:00:00', ['hidden' => true]);
        $this->job($driver, Company::factory()->create(), 900, '2026-10-20 12:00:00');

        $member = $company->members()->where('user_id', $driver->id)->get();
        $activity = CompanyActivity::forMembers($company, $member)[$member->first()->id];

        $this->assertSame(150.0, $activity['month']['distance_km']);
        $this->assertSame(1, $activity['month']['deliveries']);
        $this->assertFalse($activity['month']['met']);
        $this->assertSame(400.0, $activity['last_month']['distance_km']);
        $this->assertSame(500, $activity['last_month']['required_km']);
        $this->assertFalse($activity['last_month']['met']);
        $this->assertSame('2026-11-01T00:00:00+00:00', $activity['last_job_at']);

        // One more October job puts October over the minimum.
        $this->job($driver, $company, 100, '2026-10-31 20:00:00');
        $activity = CompanyActivity::forMembers($company, $member)[$member->first()->id];
        $this->assertTrue($activity['last_month']['met']);
    }

    public function test_a_member_who_joined_mid_month_owes_their_share_counted_from_the_join_date(): void
    {
        [$company] = $this->company(['activity_min_km' => 1000, 'activity_min_deliveries' => 4]);
        $this->travelTo(CarbonImmutable::parse('2026-12-02 12:00:00', 'UTC'));
        $late = User::factory()->create(['name' => 'Lenny Late']);
        // Joined on 22 November: 9 of November's 30 days are left.
        $member = $this->member($company, $late, CompanyRole::Driver, CarbonImmutable::parse('2026-11-22 15:00:00', 'UTC'));

        // A company job from before they joined (an earlier stint) doesn't count.
        $this->job($late, $company, 5000, '2026-11-10 12:00:00');
        $this->job($late, $company, 300, '2026-11-25 12:00:00');
        $this->job($late, $company, 10, '2026-11-26 12:00:00');

        $activity = CompanyActivity::forMembers($company, collect([$member]))[$member->id];
        $this->assertSame(300, $activity['last_month']['required_km']);
        $this->assertSame(2, $activity['last_month']['required_deliveries']);
        $this->assertSame(310.0, $activity['last_month']['distance_km']);
        $this->assertSame(2, $activity['last_month']['deliveries']);
        $this->assertTrue($activity['last_month']['met']);
        // December is a whole month for them.
        $this->assertSame(1000, $activity['month']['required_km']);

        // Someone who joined this month isn't judged on last month at all.
        $new = User::factory()->create();
        $newMember = $this->member($company, $new, CompanyRole::Driver, CarbonImmutable::parse('2026-12-01 09:00:00', 'UTC'));
        $this->assertNull(CompanyActivity::forMembers($company, collect([$newMember]))[$newMember->id]['last_month']);
        $this->assertFalse(CompanyActivity::missedLastMonth($company)->contains('user_id', $new->id));
    }

    public function test_managers_see_everyones_activity_and_drivers_only_their_own(): void
    {
        [$company, $owner, $manager, $driver] = $this->company(['activity_min_km' => 500]);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
        $this->job($driver, $company, 200, '2026-11-10 12:00:00');

        $activityOf = fn (Assert $page, string $name) => collect($page->toArray()['props']['members'])
            ->firstWhere('driver.name', $name)['activity'] ?? null;

        $this->actingAs($manager)->get(route('companies.show', $company))
            ->assertInertia(function (Assert $page) use ($activityOf) {
                $page->where('activity', ['min_km' => 500, 'min_deliveries' => null]);
                $this->assertEquals(200, $activityOf($page, 'Dora Driver')['month']['distance_km']);
                $this->assertFalse($activityOf($page, 'Dora Driver')['last_month']['met']);
                $this->assertNotNull($activityOf($page, 'Olive Owner'));
            });

        $this->actingAs($driver)->get(route('companies.show', $company))
            ->assertInertia(function (Assert $page) use ($activityOf) {
                $page->where('activity', ['min_km' => 500, 'min_deliveries' => null]);
                $this->assertEquals(200, $activityOf($page, 'Dora Driver')['month']['distance_km']);
                $this->assertNull($activityOf($page, 'Olive Owner'));
                $this->assertNull($activityOf($page, 'Mona Manager'));
            });

        // Outsiders and guests see neither the minimum nor anyone's activity.
        foreach ([User::factory()->create(), null] as $outsider) {
            ($outsider ? $this->actingAs($outsider) : $this)->get(route('companies.show', $company))
                ->assertInertia(function (Assert $page) use ($activityOf) {
                    $page->where('activity', null);
                    $this->assertNull($activityOf($page, 'Dora Driver'));
                });
            auth()->logout();
        }
    }

    public function test_the_first_of_the_month_tells_owners_and_managers_once_who_missed_it(): void
    {
        [$company, $owner, $manager, $driver] = $this->company(['activity_min_deliveries' => 2]);
        $suspended = User::factory()->create(['name' => 'Sid Suspended', 'suspended_at' => now()]);
        $this->member($company, $suspended, CompanyRole::Driver, CarbonImmutable::parse('2026-09-01', 'UTC'));
        $this->job($owner, $company, 100, '2026-10-05 12:00:00');
        $this->job($owner, $company, 100, '2026-10-06 12:00:00');
        $this->job($manager, $company, 100, '2026-10-06 12:00:00');

        // A company with no minimum and one where everyone met it get nothing.
        [$relaxed, $relaxedOwner] = $this->company(['name' => 'Relaxed', 'tag' => 'RLX']);
        [$busy, $busyOwner, $busyManager, $busyDriver] = $this->company(['name' => 'Busy', 'tag' => 'BSY', 'activity_min_km' => 50]);
        foreach ([$busyOwner, $busyManager, $busyDriver] as $u) {
            $this->job($u, $busy, 60, '2026-10-15 12:00:00');
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30:00', 'UTC'));
        $this->artisan('hub:activity-check')->assertSuccessful();

        foreach ([$owner, $manager] as $staff) {
            $notice = $staff->notifications()->sole();
            $this->assertSame(NoticeType::ActivityMissed->value, $notice->data['type']);
            $this->assertSame("2 drivers missed Active Haulage's monthly minimum in October 2026: Dora Driver, Mona Manager.", $notice->data['message']);
            $this->assertSame(route('companies.show', $company), $notice->data['url']);
        }
        $this->assertSame(0, $driver->notifications()->count());
        $this->assertSame(0, $relaxedOwner->notifications()->count());
        $this->assertSame(0, $busyOwner->notifications()->count());
        $this->assertSame('2026-10', $company->refresh()->activity_checked_month);

        // A second run the same month sends nothing more.
        $this->artisan('hub:activity-check')->assertSuccessful();
        $this->assertSame(1, $owner->notifications()->count());

        // Nobody was removed.
        $this->assertSame(4, $company->members()->count());
    }

    public function test_the_check_is_scheduled_on_the_first(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('hub:activity-check')->assertSuccessful();
    }
}
