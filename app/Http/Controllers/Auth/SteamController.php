<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Usernames;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Sign in with Steam. A known Steam account signs in (through the two-factor challenge when
 * the account has 2FA); a signed-in user links Steam to their account; a new Steam account
 * goes through a short "finish sign-up" form, since Steam gives no email address.
 */
class SteamController extends Controller
{
    use ProfileValidationRules;

    public static function enabled(): bool
    {
        return filled(config('services.steam.client_secret'));
    }

    public function redirect(): RedirectResponse|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        return Socialite::driver('steam')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        try {
            $steam = Socialite::driver('steam')->user();
        } catch (Throwable $e) {
            report($e);

            return to_route($request->user() ? 'connections.edit' : 'login')
                ->with('status', __('Steam sign-in failed. Please try again.'));
        }

        $steamId = (string) $steam->getId();
        $existing = User::query()->where('steam_id', $steamId)->first();

        if ($current = $request->user()) {
            if ($existing !== null && ! $existing->is($current)) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('That Steam account is linked to another driver.')]);

                return to_route('connections.edit');
            }

            $current->forceFill([
                'steam_id' => $steamId,
                'avatar_url' => $current->avatar_url ?? $steam->getAvatar(),
            ])->save();

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Steam account linked.')]);

            return to_route('connections.edit');
        }

        if ($existing !== null) {
            if ($existing->two_factor_confirmed_at !== null) {
                $request->session()->put(['login.id' => $existing->getKey(), 'login.remember' => true]);

                return to_route('two-factor.login');
            }

            Auth::login($existing, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        $request->session()->put('steam_signup', [
            'steam_id' => $steamId,
            'nickname' => (string) $steam->getNickname(),
            'avatar' => $steam->getAvatar(),
        ]);

        return to_route('steam.complete');
    }

    public function complete(Request $request): Response|RedirectResponse
    {
        $signup = $request->session()->get('steam_signup');
        if (! is_array($signup)) {
            return to_route('login');
        }

        return Inertia::render('auth/SteamComplete', [
            'nickname' => $signup['nickname'],
            'avatar' => $signup['avatar'],
            'suggestedUsername' => Usernames::suggest($signup['nickname']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $signup = $request->session()->get('steam_signup');
        if (! is_array($signup)) {
            return to_route('login');
        }

        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate($this->profileRules());

        if (User::query()->where('steam_id', $signup['steam_id'])->exists()) {
            $request->session()->forget('steam_signup');

            return to_route('login');
        }

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
            // Steam accounts get a random password; "Forgot password" sets a real one.
            'password' => Str::random(64),
        ]);
        $user->forceFill(['steam_id' => $signup['steam_id'], 'avatar_url' => $signup['avatar']])->save();

        $request->session()->forget('steam_signup');

        event(new Registered($user));
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['steam_id' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Steam account unlinked.')]);

        return to_route('connections.edit');
    }
}
