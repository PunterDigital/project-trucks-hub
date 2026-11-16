<?php

namespace App\Http\Controllers;

use App\Achievements\Progress;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Charts;
use App\Support\LinkPreview;
use App\Support\Period;
use App\Support\Stats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DriverController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $drivers = User::query()
            ->visibleTo($request->user())
            ->whereNotNull('username')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('username', 'like', '%'.$search.'%')))
            ->with('membership.company')
            ->withCount(['deliveries as deliveries_count' => Delivery::countedConstraint()])
            ->withSum(['deliveries as distance_km' => Delivery::countedConstraint()], 'distance_km')
            ->orderByDesc('distance_km')
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (User $u) => [
                ...$u->publicSummary(),
                'deliveries' => (int) $u->getAttribute('deliveries_count'),
                'distance_km' => round((float) $u->getAttribute('distance_km'), 1),
            ]);

        return Inertia::render('drivers/Index', [
            'drivers' => $drivers,
            'search' => $search,
        ]);
    }

    public function show(Request $request, User $driver): Response
    {
        $viewer = $request->user();
        $driver->loadMissing('membership.company', 'liveStatus');

        // Suspended (#4651): gone from the site, not even the "private profile" page.
        abort_if($driver->isSuspended(), 404);

        if (! $driver->isVisibleTo($viewer)) {
            return Inertia::render('drivers/Private', ['driver' => [
                'name' => $driver->name,
                'username' => $driver->username,
                'avatar' => $driver->avatar(),
            ]]);
        }

        $isOwner = $viewer?->is($driver) ?? false;
        $deliveries = $driver->deliveries()->getQuery();

        $trucks = $driver->deliveries()->counted()->toBase()
            ->selectRaw('truck_make, truck_model, COUNT(*) as deliveries, SUM(distance_km) as distance_km')
            ->whereNotNull('truck_make')
            ->groupBy('truck_make', 'truck_model')
            ->orderByDesc('distance_km')
            ->limit(6)
            ->get()
            ->map(fn ($t) => [
                'name' => trim($t->truck_make.' '.$t->truck_model),
                'deliveries' => (int) $t->deliveries,
                'distance_km' => round((float) $t->distance_km, 1),
            ]);

        $jobs = $driver->deliveries()
            // Others see what the job page would show them: not hidden, not waiting for a review (#4743).
            ->when(! $isOwner, fn (Builder $q) => $q->visibleTo($viewer))
            ->withReactions($viewer)
            ->latest('completed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Delivery $d) => $d->summary());

        $live = $driver->liveStatus;

        return Inertia::render('drivers/Show', [
            'driver' => [
                ...$driver->publicSummary(),
                'role' => $driver->membership?->role->value,
                'member_since' => $driver->created_at?->toIso8601String(),
                'steam_url' => $driver->steam_id ? 'https://steamcommunity.com/profiles/'.$driver->steam_id : null,
                'private' => $driver->profile_private,
            ],
            'isOwner' => $isOwner,
            'stats' => [
                'week' => Stats::totals($deliveries, Period::Week),
                'month' => Stats::totals($deliveries, Period::Month),
                'all' => Stats::totals($deliveries, Period::All),
            ],
            'onTime' => $this->onTimeRate($driver),
            'trucks' => $trucks,
            // Charts (#4650): 15-minute UTC buckets, folded into the viewer's days on the page.
            'charts' => [
                'distance' => Charts::distance($deliveries),
                'cargo' => Charts::cargo($deliveries),
            ],
            'live' => $live !== null && $live->isFresh() ? $live->summary() : null,
            'jobs' => $jobs,
            'progress' => Progress::summary($driver),
        ])->withViewData('preview', fn () => LinkPreview::meta('driver', $driver->id));
    }

    /** Share of counted deliveries that weren't late, as 0–100, or null with none. */
    private function onTimeRate(User $driver): ?float
    {
        $row = $driver->deliveries()->counted()->toBase()
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as on_time', [DeliveryStatus::Delivered->value])
            ->first();

        $total = (int) ($row->total ?? 0);

        return $total === 0 ? null : round(((int) $row->on_time) / $total * 100, 1);
    }
}
