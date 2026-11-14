<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SteamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No Web API key: sign-in is OpenID only.
        config(['services.steam.enabled' => true, 'services.steam.client_secret' => null]);
    }

    private function fakeSteam(string $id = '76561198000000001'): void
    {
        Socialite::fake('steam', (new SocialiteUser)->map([
            'id' => $id,
            'nickname' => 'Big Rig Dave',
            'avatar' => 'https://avatars.example.com/dave.jpg',
        ]));
    }

    public function test_steam_sign_in_needs_no_api_key_and_goes_to_steams_openid_page(): void
    {
        $this->get(route('login'))->assertInertia(fn ($page) => $page->where('steamEnabled', true));

        $location = $this->get(route('steam.redirect'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://steamcommunity.com/openid/login?', (string) $location);
        $this->assertStringContainsString('openid.mode=checkid_setup', (string) $location);
        $this->assertStringContainsString(urlencode(route('steam.callback')), (string) $location);
    }

    public function test_steam_can_be_switched_off(): void
    {
        config(['services.steam.enabled' => false]);

        $this->get(route('steam.redirect'))->assertNotFound();
        $this->get(route('steam.callback'))->assertNotFound();
        $this->get(route('login'))->assertInertia(fn ($page) => $page->where('steamEnabled', false));
    }

    public function test_a_steam_player_without_a_profile_gets_a_generic_suggestion(): void
    {
        Socialite::fake('steam', (new SocialiteUser)->map(['id' => '76561198000000002', 'nickname' => null, 'avatar' => null]));

        $this->get(route('steam.callback'))->assertRedirect(route('steam.complete'));
        $this->get(route('steam.complete'))->assertInertia(fn ($page) => $page
            ->where('nickname', '')
            ->where('suggestedUsername', 'driver'));
    }

    public function test_a_new_steam_player_finishes_sign_up_with_an_email(): void
    {
        $this->fakeSteam();

        $this->get(route('steam.callback'))->assertRedirect(route('steam.complete'));
        $this->get(route('steam.complete'))->assertInertia(fn ($page) => $page
            ->component('auth/SteamComplete')
            ->where('nickname', 'Big Rig Dave')
            ->where('suggestedUsername', 'big-rig-dave'));

        $this->post(route('steam.store'), [
            'name' => 'Big Rig Dave',
            'username' => 'Big-Rig-Dave',
            'email' => 'dave@example.com',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('76561198000000001', $user->steam_id);
        $this->assertSame('big-rig-dave', $user->username);
        $this->assertSame('https://avatars.example.com/dave.jpg', $user->avatar_url);
    }

    public function test_a_known_steam_account_signs_in(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['steam_id' => '76561198000000001'])->save();
        $this->fakeSteam();

        $this->get(route('steam.callback'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_accounts_still_get_the_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['steam_id' => '76561198000000001'])->save();
        $this->fakeSteam();

        $this->get(route('steam.callback'))->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_a_signed_in_driver_links_steam(): void
    {
        $user = User::factory()->create();
        $this->fakeSteam();

        $this->actingAs($user)->get(route('steam.callback'))->assertRedirect(route('connections.edit'));
        $this->assertSame('76561198000000001', $user->fresh()->steam_id);

        $this->actingAs($user)->delete(route('steam.destroy'));
        $this->assertNull($user->fresh()->steam_id);
    }

    public function test_a_steam_account_cannot_be_linked_twice(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['steam_id' => '76561198000000001'])->save();
        $user = User::factory()->create();
        $this->fakeSteam();

        $this->actingAs($user)->get(route('steam.callback'));
        $this->assertNull($user->fresh()->steam_id);
    }

    public function test_the_complete_step_needs_a_steam_session(): void
    {
        $this->get(route('steam.complete'))->assertRedirect(route('login'));
        $this->post(route('steam.store'), ['name' => 'x', 'username' => 'xyz', 'email' => 'x@example.com'])
            ->assertRedirect(route('login'));
        $this->assertSame(0, User::query()->count());
    }
}
