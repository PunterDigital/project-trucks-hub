<?php

namespace App\Support;

/**
 * What the minute auto-refresh (#4723) asks for (#5731). A page that sends `refreshOnly`
 * gets a partial Inertia reload of just those props (`useAutoRefresh` reads it), so the
 * controller's other props, closures, never run on a refresh. Pages without it still
 * reload everything.
 *
 * The bell and the signed-in user (shared props) are always in the list, so the unread
 * count and a rename keep updating.
 */
class AutoRefresh
{
    /** Shared props every refresh brings along (`HandleInertiaRequests::share`). */
    public const SHARED = ['auth', 'notifications'];

    /**
     * @param  list<string>  $pageProps  the page's props that change minute by minute
     * @return list<string>
     */
    public static function only(array $pageProps): array
    {
        return array_values(array_unique([...$pageProps, ...self::SHARED]));
    }
}
