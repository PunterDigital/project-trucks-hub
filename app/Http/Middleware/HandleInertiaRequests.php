<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\SteamController;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
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
            // The header bell (#4643). The 60 s auto-refresh reloads shared props too, so the
            // count stays current without its own poll.
            'notifications' => fn () => ($user = $request->user()) ? self::notificationsFor($user) : null,
        ];
    }

    /**
     * @return array{unread: int, items: array<int, array{id: string, type: string, message: string, read: bool, at: string|null}>}
     */
    public static function notificationsFor(User $user): array
    {
        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()
                ->limit(NotificationController::SHOWN)
                ->get()
                ->map(fn (DatabaseNotification $n) => [
                    'id' => $n->id,
                    'type' => (string) ($n->data['type'] ?? ''),
                    'message' => (string) ($n->data['message'] ?? ''),
                    'read' => $n->read_at !== null,
                    'at' => $n->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
