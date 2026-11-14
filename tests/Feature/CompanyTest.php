<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private function companyWith(CompanyRole $role, array $state = []): array
    {
        $company = Company::factory()->create($state);
        $user = User::factory()->create();
        $company->members()->create(['user_id' => $user->id, 'role' => $role]);

        return [$company, $user];
    }

    private function addMember(Company $company, CompanyRole $role = CompanyRole::Driver): User
    {
        $user = User::factory()->create();
        $company->members()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    public function test_a_driver_creates_a_company_and_owns_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('companies.store'), [
            'name' => 'Nordic Haulage',
            'tag' => 'nrdc',
            'description' => 'Scandinavia, mostly.',
            'recruitment' => 'apply',
        ])->assertRedirect(route('companies.show', 'nordic-haulage'));

        $company = Company::query()->sole();
        $this->assertSame('NRDC', $company->tag);
        $this->assertSame(CompanyRole::Owner, $company->roleOf($user->fresh()));
    }

    public function test_names_and_tags_are_unique(): void
    {
        Company::factory()->create(['name' => 'Nordic Haulage', 'tag' => 'NRDC']);

        $this->actingAs(User::factory()->create())->post(route('companies.store'), [
            'name' => 'Nordic Haulage',
            'tag' => 'NRDC',
            'recruitment' => 'open',
        ])->assertSessionHasErrors(['name', 'tag']);
    }

    public function test_a_member_cannot_create_a_second_company(): void
    {
        [, $user] = $this->companyWith(CompanyRole::Driver);

        $this->actingAs($user)->post(route('companies.store'), [
            'name' => 'Second Co',
            'tag' => 'SEC',
            'recruitment' => 'open',
        ]);

        $this->assertSame(1, Company::query()->count());
    }

    public function test_anyone_joins_an_open_company(): void
    {
        $company = Company::factory()->open()->create();
        $user = User::factory()->create();
        $other = Company::factory()->create();
        $other->applications()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('companies.join', $company))->assertRedirect(route('companies.show', $company));

        $this->assertSame(CompanyRole::Driver, $company->roleOf($user->fresh()));
        // Joining withdraws applications elsewhere.
        $this->assertSame(ApplicationStatus::Withdrawn, $other->applications()->sole()->status);
    }

    public function test_an_apply_company_cannot_be_joined_directly(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('companies.join', $company));
        $this->assertNull($company->roleOf($user->fresh()));
    }

    public function test_applications_are_accepted_or_declined_by_managers(): void
    {
        [$company, $manager] = $this->companyWith(CompanyRole::Manager);
        $applicant = User::factory()->create();
        $declined = User::factory()->create();

        $this->actingAs($applicant)->post(route('companies.apply', $company), ['message' => 'Hi!']);
        $this->actingAs($declined)->post(route('companies.apply', $company));
        $application = CompanyApplication::query()->where('user_id', $applicant->id)->sole();
        $this->assertSame('Hi!', $application->message);

        $this->actingAs($manager)->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->component('companies/Show')->has('applications', 2));

        $this->actingAs($manager)->post(route('companies.applications.decide', [$company, $application]), ['decision' => 'accept']);
        $this->assertSame(CompanyRole::Driver, $company->roleOf($applicant->fresh()));
        $this->assertSame(ApplicationStatus::Accepted, $application->fresh()->status);

        $other = CompanyApplication::query()->where('user_id', $declined->id)->sole();
        $this->actingAs($manager)->post(route('companies.applications.decide', [$company, $other]), ['decision' => 'decline']);
        $this->assertSame(ApplicationStatus::Declined, $other->fresh()->status);
        $this->assertNull($company->roleOf($declined->fresh()));
    }

    public function test_drivers_cannot_decide_applications_or_see_them(): void
    {
        [$company, $driver] = $this->companyWith(CompanyRole::Driver);
        $application = $company->applications()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($driver)->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->has('applications', 0));
        $this->actingAs($driver)
            ->post(route('companies.applications.decide', [$company, $application]), ['decision' => 'accept'])
            ->assertForbidden();
    }

    public function test_accepting_someone_who_joined_elsewhere_fails_cleanly(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $applicant = User::factory()->create();
        $application = $company->applications()->create(['user_id' => $applicant->id]);
        Company::factory()->open()->create()->members()->create(['user_id' => $applicant->id, 'role' => CompanyRole::Driver]);

        $this->actingAs($owner)->post(route('companies.applications.decide', [$company, $application]), ['decision' => 'accept']);

        $this->assertNull($company->roleOf($applicant->fresh()));
        $this->assertSame(ApplicationStatus::Withdrawn, $application->fresh()->status);
    }

    public function test_the_owner_hands_over_ownership(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $driver = $this->addMember($company);

        $this->actingAs($owner)->patch(route('companies.members.update', [$company, $driver->membership]), ['role' => 'owner']);

        $this->assertSame(CompanyRole::Owner, $company->roleOf($driver->fresh()));
        $this->assertSame(CompanyRole::Manager, $company->roleOf($owner->fresh()));
    }

    public function test_managers_cannot_change_roles(): void
    {
        [$company, $manager] = $this->companyWith(CompanyRole::Manager);
        $driver = $this->addMember($company);

        $this->actingAs($manager)
            ->patch(route('companies.members.update', [$company, $driver->membership]), ['role' => 'manager'])
            ->assertForbidden();
    }

    public function test_who_can_remove_whom(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $manager = $this->addMember($company, CompanyRole::Manager);
        $otherManager = $this->addMember($company, CompanyRole::Manager);
        $driver = $this->addMember($company);

        // A manager can't remove another manager or the owner.
        $this->actingAs($manager)->delete(route('companies.members.destroy', [$company, $otherManager->membership]));
        $this->actingAs($manager)->delete(route('companies.members.destroy', [$company, $owner->membership]));
        $this->assertNotNull($otherManager->fresh()->membership);
        $this->assertNotNull($owner->fresh()->membership);

        // A manager can remove a driver; the owner can remove a manager.
        $this->actingAs($manager)->delete(route('companies.members.destroy', [$company, $driver->membership]));
        $this->assertNull($driver->fresh()->membership);
        $this->actingAs($owner)->delete(route('companies.members.destroy', [$company, $otherManager->membership]));
        $this->assertNull($otherManager->fresh()->membership);
    }

    public function test_the_owner_cannot_leave_without_handing_over(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $driver = $this->addMember($company);

        $this->actingAs($owner)->post(route('companies.leave', $company));
        $this->assertNotNull($owner->fresh()->membership);

        $this->actingAs($driver)->post(route('companies.leave', $company));
        $this->assertNull($driver->fresh()->membership);
    }

    public function test_jobs_stay_with_the_company_after_the_driver_leaves(): void
    {
        [$company, $driver] = $this->companyWith(CompanyRole::Driver);
        Delivery::factory()->for($driver)->create(['company_id' => $company->id, 'distance_km' => 500]);

        $driver->membership->delete();

        $this->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->where('stats.all.distance_km', 500)->where('stats.all.deliveries', 1));
    }

    public function test_deleting_needs_the_owner_and_the_exact_name(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $manager = $this->addMember($company, CompanyRole::Manager);

        $this->actingAs($manager)->delete(route('companies.destroy', $company), ['confirm_name' => $company->name])->assertForbidden();
        $this->actingAs($owner)->delete(route('companies.destroy', $company), ['confirm_name' => 'nope'])->assertSessionHasErrors('confirm_name');
        $this->assertModelExists($company);

        $this->actingAs($owner)->delete(route('companies.destroy', $company), ['confirm_name' => $company->name])
            ->assertRedirect(route('companies.index'));
        $this->assertModelMissing($company);
        $this->assertNull($owner->fresh()->membership);
    }

    public function test_managers_edit_details(): void
    {
        [$company, $manager] = $this->companyWith(CompanyRole::Manager);
        [, $outsider] = $this->companyWith(CompanyRole::Owner);

        $this->actingAs($outsider)->get(route('companies.edit', $company))->assertForbidden();

        $this->actingAs($manager)->put(route('companies.update', $company), [
            'name' => 'Renamed Haulage',
            'tag' => 'RNM',
            'description' => null,
            'recruitment' => 'closed',
        ])->assertRedirect(route('companies.show', 'renamed-haulage'));

        $this->assertSame('renamed-haulage', $company->fresh()->slug);
    }

    public function test_pages_render_for_guests(): void
    {
        $company = Company::factory()->create();

        $this->get(route('companies.index'))->assertInertia(fn ($page) => $page->component('companies/Index')->has('companies.data', 1));
        $this->get(route('companies.show', $company))->assertInertia(fn ($page) => $page->component('companies/Show')->where('role', null));
        $this->get(route('companies.create'))->assertRedirect(route('login'));
    }
}
