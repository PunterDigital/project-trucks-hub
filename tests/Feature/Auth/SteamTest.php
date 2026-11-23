<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    /** Start a Steam flow in this browser (as a real sign-in would) and return its callback URL. */
    private function steamCallback(): string
    {
        if (! is_string(session('steam_state'))) {
            $this->get(route('steam.redirect'));
        }

        return route('steam.callback', ['state' => session('steam_state')]);
    }

    public function test_the_return_url_carries_a_state_kept_in_the_session(): void
    {
        $location = (string) $this->get(route('steam.redirect'))->assertRedirect()->headers->get('Location');

        $state = session('steam_state');
        $this->assertIsString($state);
        $this->assertSame(40, strlen($state));
        $this->assertStringContainsString(urlencode(route('steam.callback', ['state' => $state])), $location);
    }

    public function test_a_callback_without_a_flow_started_in_this_session_links_nothing(): void
    {
        // #5519: the attacker's own callback URL, opened by a signed-in driver.
        $victim = User::factory()->create();
        $this->fakeSteam('76561198000000666');

        $this->actingAs($victim)->get(route('steam.callback', ['state' => Str::random(40)]))
            ->assertRedirect(route('connections.edit'));
        $this->actingAs($victim)->get(route('steam.callback'))->assertRedirect(route('connections.edit'));

        $this->assertNull($victim->fresh()->steam_id);
    }

    public function test_a_callback_with_another_flows_state_links_nothing(): void
    {
        $victim = User::factory()->create();
        $this->fakeSteam('76561198000000666');

        // The victim has a Steam flow of their own open; the attacker's URL carries a different state.
        $this->actingAs($victim)->get(route('steam.redirect'));
        $this->actingAs($victim)->get(route('steam.callback', ['state' => 'attacker-state-'.Str::random(26)]))
            ->assertRedirect(route('connections.edit'));

        $this->assertNull($victim->fresh()->steam_id);
        $this->assertNull(session('steam_state'), 'a failed callback uses up the pending state');
    }

    public function test_a_state_works_once(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['steam_id' => '76561198000000001'])->save();
        $this->fakeSteam();

        $callback = $this->steamCallback();
        $this->get($callback)->assertRedirect(route('dashboard'));
        auth()->logout();

        $this->get($callback)->assertRedirect(route('login'));
        $this->assertGuest();
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

        $this->get($this->steamCallback())->assertRedirect(route('steam.complete'));
        $this->get(route('steam.complete'))->assertInertia(fn ($page) => $page
            ->where('nickname', '')
            ->where('suggestedUsername', 'driver'));
    }

    public function test_a_new_steam_player_finishes_sign_up_with_an_email(): void
    {
        $this->fakeSteam();

        $this->get($this->steamCallback())->assertRedirect(route('steam.complete'));
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

        $this->get($this->steamCallback())->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_accounts_still_get_the_challenge(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['steam_id' => '76561198000000001'])->save();
        $this->fakeSteam();

        $this->get($this->steamCallback())->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_a_signed_in_driver_links_steam(): void
    {
        $user = User::factory()->create();
        $this->fakeSteam();

        $this->actingAs($user)->get($this->steamCallback())->assertRedirect(route('connections.edit'));
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

        $this->actingAs($user)->get($this->steamCallback());
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
