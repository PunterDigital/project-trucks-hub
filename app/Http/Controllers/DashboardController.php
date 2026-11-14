<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Feed;
use App\Support\Period;
use App\Support\Stats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in driver's own overview. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('membership.company', 'liveStatus');
        $deliveries = $user->deliveries()->getQuery();
        $live = $user->liveStatus;

        return Inertia::render('Dashboard', [
            'driver' => $user->publicSummary(),
            'role' => $user->membership?->role->value,
            'linkedApps' => $user->tokens()->count(),
            'jobsSynced' => $user->deliveries()->count(),
            'stats' => [
                'week' => Stats::totals($deliveries, Period::Week),
                'month' => Stats::totals($deliveries, Period::Month),
                'all' => Stats::totals($deliveries, Period::All),
            ],
            'live' => $live !== null && $live->isFresh() ? $live->summary() : null,
            'recent' => Feed::latestDeliveries($user, 10, fn ($q) => $q->where('deliveries.user_id', $user->id)),
            'downloadUrl' => 'https://punterdigital.github.io/personal-apps/',
        ]);
    }
}
