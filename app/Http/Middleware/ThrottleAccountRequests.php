<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Card 5532: Fortify's sign-up and "forgot password" routes have no limiter
 * of their own. Each sign-up makes a pending organisation and notifies every
 * staff member, so one IP could flood the staff inbox and the mail quota.
 * Limits are per IP (config auth.account_limits); going over shows the
 * form's email error instead of a bare 429 page.
 */
class ThrottleAccountRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = $this->limitFor($request);

        if ($limit === null) {
            return $next($request);
        }

        [$key, $max, $decaySeconds] = $limit;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw ValidationException::withMessages([
                'email' => trans_choice(
                    'Too many attempts. Please try again in :count minute.|Too many attempts. Please try again in :count minutes.',
                    $minutes,
                ),
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);

        return $next($request);
    }

    /**
     * @return array{0: string, 1: int, 2: int}|null
     */
    private function limitFor(Request $request): ?array
    {
        if (! $request->isMethod('POST')) {
            return null;
        }

        if ($request->routeIs('register.store')) {
            return ['account-register|'.$request->ip(), (int) config('auth.account_limits.register_per_hour'), 3600];
        }

        if ($request->routeIs('password.email')) {
            return ['account-password-reset|'.$request->ip(), (int) config('auth.account_limits.password_resets_per_minute'), 60];
        }

        return null;
    }
}
