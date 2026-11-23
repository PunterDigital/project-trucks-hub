<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Steam sign-ups get a random password nobody knows, so nothing may ask them for it (#4672).
 */
class SteamPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const STEAM_ID = '76561198000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.steam.enabled' => true, 'services.steam.client_secret' => null]);
    }

    private function steamOnlyUser(): User
    {
        $user = User::factory()->create(['password' => Str::random(64)]);
        $user->forceFill(['steam_id' => self::STEAM_ID, 'password_set_at' => null])->save();

        return $user->refresh();
    }

    /** Start a Steam flow in this browser (unless one is open) and return its callback URL. */
    private function steamCallback(): string
    {
        if (! is_string(session('steam_state'))) {
            $this->get(route('steam.redirect'));
        }

        return route('steam.callback', ['state' => session('steam_state')]);
    }

    private function fakeSteam(string $id = self::STEAM_ID): void
    {
        Socialite::fake('steam', (new SocialiteUser)->map(['id' => $id, 'nickname' => 'Dave', 'avatar' => null]));
    }

    public function test_steam_sign_up_leaves_the_password_unset_and_email_sign_up_sets_it(): void
    {
        $this->fakeSteam();
        $this->get($this->steamCallback());
        $this->post(route('steam.store'), ['name' => 'Dave', 'username' => 'dave', 'email' => 'dave@example.com']);
        $this->assertFalse(User::query()->where('email', 'dave@example.com')->sole()->hasPassword());

        auth()->logout();
        $this->post(route('register.store'), [
            'name' => 'Erin',
            'username' => 'erin',
            'email' => 'erin@example.com',
            'password' => 'password-123-long',
            'password_confirmation' => 'password-123-long',
        ]);
        $this->assertTrue(User::query()->where('email', 'erin@example.com')->sole()->hasPassword());
    }

    public function test_the_confirm_page_offers_steam_and_no_password_field_to_a_steam_only_user(): void
    {
        $user = $this->steamOnlyUser();

        $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
        $this->actingAs($user)->get(route('password.confirm'))->assertInertia(fn ($page) => $page
            ->component('auth/ConfirmPassword')
            ->where('hasPassword', false)
            ->where('canConfirmWithSteam', true));
    }

    public function test_a_password_user_still_sees_the_password_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('password.confirm'))->assertInertia(fn ($page) => $page
            ->where('hasPassword', true)
            ->where('canConfirmWithSteam', false));
    }

    public function test_confirming_with_the_linked_steam_account_opens_the_security_page(): void
    {
        $user = $this->steamOnlyUser();
        $this->fakeSteam();

        $this->actingAs($user)->get(route('security.edit'));
        $this->actingAs($user)->get(route('steam.confirm'))->assertRedirect();
        $this->actingAs($user)->get($this->steamCallback())->assertRedirect(route('security.edit'));

        $this->actingAs($user)->get(route('security.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/Security')->where('hasPassword', false));
        $this->assertSame(self::STEAM_ID, $user->refresh()->steam_id);
    }

    public function test_a_different_steam_account_does_not_confirm(): void
    {
        $user = $this->steamOnlyUser();
        $this->fakeSteam('76561198000000099');

        $this->actingAs($user)->get(route('steam.confirm'));
        $this->actingAs($user)->get($this->steamCallback())->assertRedirect(route('password.confirm'));

        $this->assertNull(session('auth.password_confirmed_at'));
        $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
        $this->assertSame(self::STEAM_ID, $user->refresh()->steam_id);
    }

    public function test_steam_confirm_needs_a_linked_steam_account(): void
    {
        $this->actingAs(User::factory()->create())->get(route('steam.confirm'))->assertNotFound();
    }

    public function test_a_steam_only_user_sets_a_first_password_without_a_current_one(): void
    {
        $user = $this->steamOnlyUser();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $user->refresh();
        $this->assertTrue($user->hasPassword());
        $this->assertTrue(Hash::check('new-password-123', $user->password));
    }

    public function test_setting_a_first_password_needs_a_recent_confirmation(): void
    {
        $user = $this->steamOnlyUser();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertFalse($user->refresh()->hasPassword());
    }

    public function test_once_set_the_current_password_is_required_again(): void
    {
        $user = $this->steamOnlyUser();
        $user->setChosenPassword('first-password-123');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'password' => 'second-password-123',
                'password_confirmation' => 'second-password-123',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_a_password_reset_marks_the_password_as_set(): void
    {
        $user = $this->steamOnlyUser();

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'reset-password-123',
            'password_confirmation' => 'reset-password-123',
        ]);

        $this->assertTrue($user->refresh()->hasPassword());
    }

    public function test_a_steam_only_user_deletes_their_account_after_confirming(): void
    {
        $user = $this->steamOnlyUser();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('hasPassword', false));

        $this->actingAs($user)->delete(route('profile.destroy'))->assertRedirect(route('password.confirm'));
        $this->assertNotNull($user->fresh());

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('profile.destroy'))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');
        $this->assertNull($user->fresh());
    }

    public function test_a_password_user_still_needs_their_password_to_delete(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh());
    }
}
