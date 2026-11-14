<?php

namespace App\Http\Controllers;

use App\Models\LiveStatus;
use App\Support\MapTiles;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The live map (#4687): drivers on the road right now, on a job or free-roaming. Only public
 * profiles that left "Show me on the live map" on, with a position from the last
 * {@see LiveStatus::FRESH_SECONDS} seconds. The page polls `drivers` every 30 s.
 */
class MapController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('map/Index', [
            'tiles' => MapTiles::info(),
            'drivers' => fn (): array => self::drivers(),
            'refreshSeconds' => 30,
        ]);
    }

    /** @return array<int, array<string, mixed>> Sequential (`values()`), so it stays a JSON array. */
    public static function drivers(): array
    {
        return LiveStatus::query()
            ->fresh()
            ->whereNotNull('pos_x')
            ->whereNotNull('pos_z')
            ->whereHas('user', fn (Builder $u) => $u
                ->whereNull('suspended_at')
                ->where('profile_private', false)
                ->where('show_on_map', true))
            ->with('user.membership.company')
            ->orderBy('user_id')
            ->get()
            ->map(function (LiveStatus $s): array {
                $truck = trim(($s->truck_make ?? '').' '.($s->truck_model ?? ''));

                return [
                    'id' => $s->user_id,
                    'x' => round((float) $s->pos_x, 1),
                    'z' => round((float) $s->pos_z, 1),
                    'heading' => $s->heading_deg,
                    'truck' => $truck === '' ? null : $truck,
                    'job' => $s->cargo_name === null ? null : [
                        'cargo' => $s->cargo_name,
                        'from' => $s->source_city,
                        'to' => $s->dest_city,
                    ],
                    'parked' => $s->parked,
                    'driver' => $s->user->publicSummary(),
                ];
            })
            ->values()
            ->all();
    }
}
