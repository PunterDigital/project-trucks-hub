<?php

namespace App\Http\Middleware;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The admin panel (#4664) is for staff only, and it doesn't admit it exists: guests and drivers
 * get a 404, not a login page or a 403. Staff without two-factor authentication are sent to
 * Settings → Security to turn it on (Fortify then asks for the code at every login, so a
 * session of a 2FA account has passed it).
 *
 * This replaces Filament's `Authenticate` rather than running next to it: Laravel's middleware
 * priority puts any `AuthenticatesRequests` middleware first, so a separate check behind it
 * never got to answer 404.
 */
class AdminAuthenticate extends Authenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        // A suspended staff account (#4651) is turned away like a driver.
        if (! $user instanceof User || ! $user->is_staff || $user->isSuspended()) {
            abort(404);
        }

        if ($user->two_factor_confirmed_at === null) {
            if (! $request->isMethod('GET') || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(403, 'Turn on two-factor authentication first.');
            }

            throw new HttpResponseException(redirect()->route('security.edit'));
        }

        parent::authenticate($request, $guards);
    }
}
