<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The same "confirmed recently" check as Laravel's RequirePassword middleware, for actions
 * where a driver without a password of their own (Steam sign-up) can't type one (#4672).
 */
final class PasswordConfirmation
{
    public static function isRecent(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return time() - $confirmedAt < (int) config('auth.password_timeout', 10800);
    }
}
