<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryComment;
use App\Support\LinkPreview;
use Illuminate\Database\Eloquent\Builder;
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
        $delivery = Delivery::query()->withReactions($viewer)->findOrFail($delivery->id)->setRelations($delivery->getRelations());

        // Comments (#4646), oldest first; suspended drivers' comments are left out.
        $comments = $delivery->comments()
            ->whereHas('user', fn (Builder $u) => $u->whereNull('suspended_at'))
            ->with('user.membership.company')
            ->oldest()
            ->oldest('id')
            ->get()
            ->map(fn (DeliveryComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'created_at' => $c->created_at?->toIso8601String(),
                'author' => $c->user->publicSummary(),
                'can_delete' => $c->setRelation('delivery', $delivery)->canBeDeletedBy($viewer),
            ])
            ->values()
            ->all();

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
                // #4743: only the driver ever sees a pending or rejected job, with why.
                'review_reasons' => $delivery->reviewState() === Delivery::REVIEW_PENDING ? $delivery->flagReasons() : [],
                'review_note' => $delivery->reviewState() === Delivery::REVIEW_REJECTED ? $delivery->review_note : null,
                'review_time' => Delivery::REVIEW_TIME,
                'game' => $delivery->game,
                'company' => $delivery->company?->summary(),
            ],
            'isOwner' => $viewer?->is($delivery->user) ?? false,
            'comments' => $comments,
            'commentMaxLength' => DeliveryComment::MAX_LENGTH,
        ])->withViewData('preview', fn () => LinkPreview::meta('job', $delivery->id));
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
