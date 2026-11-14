<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryController extends Controller
{
    public function show(Request $request, Delivery $delivery): Response
    {
        $viewer = $request->user();
        abort_unless(Delivery::query()->visibleTo($viewer)->whereKey($delivery->id)->exists(), 404);

        $delivery->loadMissing('user.membership.company', 'company');

        return Inertia::render('jobs/Show', [
            'job' => [
                ...$delivery->summary(),
                'source_company' => $delivery->source_company,
                'dest_company' => $delivery->dest_company,
                'duration_sec' => $delivery->duration_sec,
                'avg_speed_kmh' => $delivery->avg_speed_kmh,
                'fuel_used_l' => $delivery->fuel_used_l,
                'damage' => $delivery->damage,
                'started_at' => $delivery->started_at->toIso8601String(),
                'source' => $delivery->source,
                'flag_reason' => $delivery->flagged ? $delivery->flag_reason : null,
                'hub_flag_reason' => $delivery->hub_flag_reason,
                'hub_held' => $delivery->hub_held,
                'game' => $delivery->game,
                'company' => $delivery->company?->summary(),
            ],
            'isOwner' => $viewer?->is($delivery->user) ?? false,
        ]);
    }

    /** The driver hides a job from everyone else (or shows it again). */
    public function update(Request $request, Delivery $delivery): RedirectResponse
    {
        abort_unless($request->user()?->id === $delivery->user_id, 403);

        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $delivery->forceFill(['hidden' => $data['hidden']])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['hidden']
            ? __('Job hidden. Only you can see it now.')
            : __('Job visible again.')]);

        return back();
    }
}
