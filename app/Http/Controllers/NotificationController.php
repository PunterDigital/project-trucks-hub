<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The header bell (#4643). Its list comes from the shared `notifications` prop
 * (`HandleInertiaRequests::notificationsFor()`); these only mark things read.
 */
class NotificationController extends Controller
{
    /** How many the bell shows. Older ones stay in the table (and the privacy export). */
    public const SHOWN = 15;

    /** Opening a notification marks it read and goes where it points. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var DatabaseNotification $found */
        $found = $user->notifications()->whereKey($notification)->firstOrFail();
        $found->markAsRead();

        $url = $found->data['url'] ?? null;

        // Only our own pages: the url was built by `route()`, but never redirect off-site.
        return is_string($url) && self::isOwnUrl($url)
            ? redirect()->to($url)
            : back();
    }

    /**
     * Same scheme, host and port as the site (#5531). A prefix check on `url('/')` alone let
     * `https://hub.example.com.evil.test` through.
     */
    public static function isOwnUrl(string $url): bool
    {
        $own = parse_url(url('/'));
        $target = parse_url($url);
        if (! is_array($own) || ! is_array($target) || ! isset($target['host'], $own['host'])) {
            return false;
        }

        return strtolower($target['scheme'] ?? '') === strtolower($own['scheme'] ?? '')
            && strtolower($target['host']) === strtolower($own['host'])
            && ($target['port'] ?? null) === ($own['port'] ?? null)
            && ! isset($target['user']) && ! isset($target['pass']);
    }

    public function readAll(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
