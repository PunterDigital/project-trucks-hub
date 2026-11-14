<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspended accounts (#4651) can't use the site: whichever way they signed in (password,
 * passkey, Steam, an old session), the next request signs them out and the login page says why.
 */
class SignOutSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = __('This account is suspended. If you think that’s a mistake, contact us via the privacy page.');

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $message], 403);
            }

            return redirect()->route('login')->with('status', $message);
        }

        return $next($request);
    }
}
