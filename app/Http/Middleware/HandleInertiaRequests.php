<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\SteamController;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Only these fields, never the model: it would send whatever relations a
            // controller loaded on it.
            'auth' => [
                'user' => fn () => ($user = $request->user()) ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'avatar' => $user->avatar_url,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'profile_private' => $user->profile_private,
                    'company' => $user->membership?->company?->summary(),
                    // Shows the "Admin" menu link (#4664); /admin checks for itself.
                    'is_staff' => (bool) $user->is_staff,
                ] : null,
            ],
            'steamEnabled' => SteamController::enabled(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
