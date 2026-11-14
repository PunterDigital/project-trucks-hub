<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Privacy policy, terms, data export and "delete my synced jobs" (#4641). */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Company, Company} */
    private function driverWithEverything(): array
    {
        $user = User::factory()->create(['email' => 'dave@example.com']);
        $company = Company::factory()->create(['name' => 'Nordic Haulage']);
        $company->members()->create(['user_id' => $user->id, 'role' => CompanyRole::Driver]);
        $other = Company::factory()->create(['name' => 'Iberia Freight']);
        CompanyApplication::query()->create(['company_id' => $other->id, 'user_id' => $user->id, 'message' => 'Hi!', 'status' => 'pending']);
        Delivery::factory()->for($user)->count(2)->create();
        LiveStatus::query()->create(['user_id' => $user->id, 'cargo_name' => 'Apples', 'updated_at' => now()]);
        $user->createToken('ProjectTrucks on RIG', ['sync']);

        return [$user, $company, $other];
    }

    public function test_guests_can_read_the_privacy_policy_and_terms(): void
    {
        $this->get(route('privacy'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('legal/Privacy')
            ->where('contactEmail', null));
        $this->get(route('terms'))->assertOk()->assertInertia(fn ($page) => $page->component('legal/Terms'));
    }

    public function test_the_contact_email_comes_from_config_and_must_be_an_email(): void
    {
        config(['services.hub.contact_email' => 'privacy@example.com']);
        $this->get(route('privacy'))->assertInertia(fn ($page) => $page->where('contactEmail', 'privacy@example.com'));

        config(['services.hub.contact_email' => 'not an email']);
        $this->get(route('terms'))->assertInertia(fn ($page) => $page->where('contactEmail', null));
    }

    public function test_the_settings_page_shows_counts(): void
    {
        [$user] = $this->driverWithEverything();

        $this->actingAs($user)->get(route('privacy-settings.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Privacy')
                ->where('jobCount', 2)
                ->where('linkedApps', 1));
    }

    public function test_the_export_holds_everything_about_the_driver_and_no_secrets(): void
    {
        [$user] = $this->driverWithEverything();
        Delivery::factory()->create(['cargo_name' => 'Someone else\'s cargo']);

        $response = $this->actingAs($user)->get(route('privacy-settings.export'));
        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('dave@example.com', $data['account']['email']);
        $this->assertSame('Nordic Haulage', $data['company']['name']);
        $this->assertSame('driver', $data['company']['role']);
        $this->assertSame('Iberia Freight', $data['company_applications'][0]['company']);
        $this->assertSame('ProjectTrucks on RIG', $data['linked_apps'][0]['name']);
        $this->assertSame('Apples', $data['live_status']['cargo_name']);
        $this->assertCount(2, $data['jobs']);
        $this->assertArrayHasKey('client_id', $data['jobs'][0]);

        $this->assertStringNotContainsString('Someone else', $body);
        $this->assertStringNotContainsString($user->password, $body);
        $this->assertStringNotContainsString('two_factor_secret', $body);
        $this->assertStringNotContainsString('"token"', $body);
    }

    public function test_guests_cannot_export_or_delete(): void
    {
        $this->get(route('privacy-settings.export'))->assertRedirect(route('login'));
        $this->delete(route('privacy-settings.jobs.destroy'))->assertRedirect(route('login'));
    }

    public function test_deleting_synced_jobs_keeps_the_account_and_other_drivers_jobs(): void
    {
        [$user, $company] = $this->driverWithEverything();
        $someoneElse = Delivery::factory()->create();

        $this->actingAs($user)->delete(route('privacy-settings.jobs.destroy'))
            ->assertRedirect(route('privacy-settings.edit'));

        $this->assertSame(0, Delivery::query()->where('user_id', $user->id)->count());
        $this->assertNotNull($someoneElse->fresh());
        $this->assertNotNull($user->fresh());
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(CompanyRole::Driver, $company->roleOf($user->fresh()));
    }

    public function test_deleting_the_account_removes_jobs_live_status_tokens_and_applications(): void
    {
        [$user, $company] = $this->driverWithEverything();
        $someoneElse = Delivery::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertSame(0, Delivery::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, LiveStatus::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, CompanyApplication::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, $company->members()->where('user_id', $user->id)->count());
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
        $this->assertNotNull($someoneElse->fresh());
    }

    public function test_the_footer_sign_up_and_link_pages_point_at_the_policy(): void
    {
        // The Vue pages link with Wayfinder helpers; check the routes they use exist and answer.
        $this->assertSame(url('/privacy'), route('privacy'));
        $this->assertSame(url('/terms'), route('terms'));
        $this->get(route('register'))->assertOk();
    }
}
