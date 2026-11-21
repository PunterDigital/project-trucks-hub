<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Company invite links (#5221). */
class CompanyInviteTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User} */
    private function companyWith(CompanyRole $role, Recruitment $recruitment = Recruitment::Closed): array
    {
        $company = Company::factory()->create(['recruitment' => $recruitment]);
        $user = User::factory()->create();
        $company->members()->create(['user_id' => $user->id, 'role' => $role]);

        return [$company, $user];
    }

    /** Creates a link through the edit page and returns its plain token from the flash. */
    private function createLink(User $manager, Company $company, int $days = 7, int $uses = 1): string
    {
        $this->actingAs($manager)
            ->post(route('companies.invites.store', $company), ['expires_days' => $days, 'max_uses' => $uses])
            ->assertRedirect(route('companies.edit', $company));

        $url = session(SessionKey::FLASH_DATA)['invite']['url'];
        $this->assertMatchesRegularExpression('#/invite/[A-Za-z0-9]{40}$#', $url);

        return substr($url, -40);
    }

    public function test_a_manager_creates_a_link_and_only_its_hash_is_stored(): void
    {
        [$company, $manager] = $this->companyWith(CompanyRole::Manager);

        $token = $this->createLink($manager, $company, 7, 5);

        $invite = CompanyInvite::query()->sole();
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertSame(5, $invite->max_uses);
        $this->assertTrue($invite->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertStringNotContainsString($token, (string) json_encode($invite->getAttributes()));

        // The edit page lists it without any token.
        $this->get(route('companies.edit', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('invites', 1)
                ->where('invites.0.max_uses', 5)
                ->where('invites.0.uses', 0)
                ->missing('invites.0.token_hash'));
    }

    public function test_never_and_unlimited_store_nulls(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);

        $this->createLink($owner, $company, 0, 0);

        $invite = CompanyInvite::query()->sole();
        $this->assertNull($invite->expires_at);
        $this->assertNull($invite->max_uses);
    }

    public function test_drivers_and_outsiders_cannot_create_or_revoke_links(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);
        $outsider = User::factory()->create();
        [$invite] = CompanyInvite::issue($company, $owner, 7, 1);

        foreach ([$driver, $outsider] as $user) {
            $this->actingAs($user)
                ->post(route('companies.invites.store', $company), ['expires_days' => 7, 'max_uses' => 1])
                ->assertForbidden();
            $this->actingAs($user)
                ->delete(route('companies.invites.destroy', [$company, $invite]))
                ->assertForbidden();
        }
        $this->assertSame(1, CompanyInvite::query()->count());
        $this->assertNull($invite->fresh()->revoked_at);
    }

    public function test_only_the_offered_choices_are_accepted(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);

        $this->actingAs($owner)
            ->post(route('companies.invites.store', $company), ['expires_days' => 365, 'max_uses' => 1000])
            ->assertSessionHasErrors(['expires_days', 'max_uses']);
        $this->assertSame(0, CompanyInvite::query()->count());
    }

    public function test_a_company_has_at_most_twenty_live_links(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        for ($i = 0; $i < CompanyInvite::MAX_ACTIVE; $i++) {
            CompanyInvite::issue($company, $owner, 7, 1);
        }

        $this->actingAs($owner)
            ->post(route('companies.invites.store', $company), ['expires_days' => 7, 'max_uses' => 1]);
        $this->assertSame(CompanyInvite::MAX_ACTIVE, CompanyInvite::query()->count());

        // A revoked one makes room.
        CompanyInvite::query()->first()->forceFill(['revoked_at' => now()])->save();
        $this->actingAs($owner)
            ->post(route('companies.invites.store', $company), ['expires_days' => 7, 'max_uses' => 1]);
        $this->assertSame(CompanyInvite::MAX_ACTIVE + 1, CompanyInvite::query()->count());
    }

    public function test_a_driver_joins_a_closed_company_through_a_link(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner, Recruitment::Closed);
        $token = $this->createLink($owner, $company);
        $driver = User::factory()->create();
        $other = Company::factory()->create();
        $other->applications()->create(['user_id' => $driver->id]);

        $this->actingAs($driver)
            ->post(route('invites.accept', $token))
            ->assertRedirect(route('companies.show', $company));

        $this->assertSame(CompanyRole::Driver, $company->roleOf($driver->fresh()));
        $this->assertSame(1, CompanyInvite::query()->sole()->uses);
        // Joining withdraws applications elsewhere, as any join does.
        $this->assertSame(ApplicationStatus::Withdrawn, $other->applications()->sole()->status);
        // The owner hears about it.
        $this->assertSame(NoticeType::MemberJoined->value, $owner->notifications()->sole()->data['type']);
    }

    public function test_a_used_up_link_lets_no_one_else_in(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $token = $this->createLink($owner, $company, 7, 1);
        [$first, $second] = User::factory()->count(2)->create();

        $this->actingAs($first)->post(route('invites.accept', $token));
        $this->actingAs($second)
            ->post(route('invites.accept', $token))
            ->assertRedirect(route('invites.show', $token));

        $this->assertNull($company->roleOf($second->fresh()));
        $this->assertSame(1, CompanyInvite::query()->sole()->uses);
        $this->get(route('invites.show', $token))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('invites/Show')
                ->where('problem', 'This invite link has been used up.'));
    }

    public function test_the_last_use_cannot_be_claimed_twice(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        [$invite] = CompanyInvite::issue($company, $owner, 7, 1);
        // Two requests that both loaded the link before either joined.
        $stale = CompanyInvite::query()->findOrFail($invite->id);

        $this->assertTrue($invite->claim());
        $this->assertFalse($stale->claim());
        $this->assertSame(1, $invite->fresh()->uses);
    }

    public function test_expired_and_revoked_links_are_refused(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $expiredToken = $this->createLink($owner, $company, 1, 0);
        $revokedToken = $this->createLink($owner, $company, 7, 0);
        [$expired, $revoked] = CompanyInvite::query()->orderBy('id')->get()->all();
        $this->actingAs($owner)->delete(route('companies.invites.destroy', [$company, $revoked]));
        $this->assertNotNull($revoked->fresh()->revoked_at);

        $this->travel(2)->days();
        $driver = User::factory()->create();

        foreach ([$expiredToken => 'This invite link has expired.', $revokedToken => 'This invite link has been revoked.'] as $token => $problem) {
            $this->actingAs($driver)->post(route('invites.accept', $token))
                ->assertRedirect(route('invites.show', $token));
            $this->assertSame($problem, session(SessionKey::FLASH_DATA)['toast']['message']);
        }
        $this->assertNull($driver->fresh()->membership);
        $this->assertSame(0, $expired->fresh()->uses);

        // Neither is listed on the edit page any more.
        $this->actingAs($owner)->get(route('companies.edit', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('invites', 0));
    }

    public function test_a_driver_in_another_company_cannot_use_a_link_and_it_keeps_its_use(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $token = $this->createLink($owner, $company, 7, 1);
        [, $elsewhere] = $this->companyWith(CompanyRole::Driver);

        $this->actingAs($elsewhere)->post(route('invites.accept', $token))
            ->assertRedirect(route('invites.show', $token));

        $this->assertNull($company->roleOf($elsewhere->fresh()));
        $this->assertSame(0, CompanyInvite::query()->sole()->uses);
        $this->get(route('invites.show', $token))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('problem', null)
                ->whereNot('viewer.otherCompany', null));
    }

    public function test_a_member_opening_their_own_companys_link_uses_nothing(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $token = $this->createLink($owner, $company, 7, 1);

        $this->actingAs($owner)->post(route('invites.accept', $token))
            ->assertRedirect(route('companies.show', $company));
        $this->assertSame(0, CompanyInvite::query()->sole()->uses);
    }

    public function test_a_guest_sees_the_company_and_comes_back_after_signing_in(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $token = $this->createLink($owner, $company);
        auth()->logout();

        $this->get(route('invites.show', $token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('invites/Show')
                ->where('company.name', $company->name)
                ->where('company.members', 1)
                ->where('problem', null)
                ->where('viewer.signedIn', false));
        $this->assertSame(route('invites.show', $token), session('url.intended'));

        // Guests can't accept.
        $this->post(route('invites.accept', $token))->assertRedirect(route('login'));
        $this->assertSame(0, CompanyInvite::query()->sole()->uses);
    }

    public function test_an_unknown_token_says_so(): void
    {
        $token = str_repeat('a', 40);

        $this->get(route('invites.show', $token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company', null)
                ->where('problem', 'This invite link doesn’t exist. Ask the company for a new one.'));

        $this->actingAs(User::factory()->create())
            ->post(route('invites.accept', $token))
            ->assertRedirect(route('invites.show', $token));
    }

    public function test_a_malformed_token_is_not_a_route(): void
    {
        $this->get('/invite/short')->assertNotFound();
        $this->get('/invite/'.str_repeat('a', 39).'-')->assertNotFound();
    }

    public function test_a_link_of_another_company_cannot_be_revoked_through_yours(): void
    {
        [$mine, $me] = $this->companyWith(CompanyRole::Owner);
        [$theirs, $them] = $this->companyWith(CompanyRole::Owner);
        [$invite] = CompanyInvite::issue($theirs, $them, 7, 1);

        $this->actingAs($me)
            ->delete(route('companies.invites.destroy', [$mine, $invite]))
            ->assertNotFound();
        $this->assertNull($invite->fresh()->revoked_at);
    }

    public function test_the_invite_page_is_not_indexed(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $token = $this->createLink($owner, $company);

        $this->get(route('invites.show', $token))
            ->assertSee('<meta name="robots" content="noindex">', false);
    }
}
