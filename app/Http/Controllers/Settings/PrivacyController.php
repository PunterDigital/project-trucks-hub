<?php

namespace App\Http\Controllers\Settings;

use App\Achievements\Progress;
use App\Http\Controllers\Controller;
use App\Models\CompanyApplication;
use App\Models\CompanyEvent;
use App\Models\Delivery;
use App\Models\DeliveryComment;
use App\Models\DeliveryKudos;
use App\Models\EventRsvp;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Privacy: download everything the Hub holds about you, or delete your synced jobs
 * and keep the account (#4641). Deleting the account itself is Settings → Profile.
 */
class PrivacyController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Privacy', [
            'jobCount' => $user->deliveries()->count(),
            'linkedApps' => $user->tokens()->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $name = 'projecttrucks-hub-'.($user->username ?? $user->id).'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($user) {
            echo json_encode(self::exportFor($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }, $name, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function destroyJobs(Request $request): RedirectResponse
    {
        $deleted = $request->user()->deliveries()->delete();
        Progress::refresh($request->user(), notify: false);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} No synced jobs to delete.|{1} Deleted 1 synced job.|[2,*] Deleted :count synced jobs.', $deleted)]);

        return to_route('privacy-settings.edit');
    }

    /**
     * Everything stored about one driver. Secrets (password hash, 2FA secrets, token hashes,
     * passkey keys) are left out: they are useless outside the Hub.
     *
     * @return array<string, mixed>
     */
    public static function exportFor(User $user): array
    {
        $user->loadMissing(['membership.company', 'liveStatus']);

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'profile_private' => $user->profile_private,
                'steam_id' => $user->steam_id,
                'avatar_url' => $user->avatar_url,
                // An uploaded picture (#4644), as the address it is served from.
                'uploaded_avatar' => $user->avatar_path === null ? null : url(ImageStore::url($user->avatar_path)),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'company' => $user->membership === null ? null : [
                'name' => $user->membership->company->name,
                'slug' => $user->membership->company->slug,
                'role' => $user->membership->getAttribute('role'),
                'joined_at' => $user->membership->created_at?->toIso8601String(),
            ],
            'level' => (int) $user->level,
            'achievements' => $user->achievements()->orderBy('unlocked_at')->get()
                ->map(fn ($a) => ['achievement' => $a->achievement->value, 'unlocked_at' => $a->unlocked_at->toIso8601String()])
                ->all(),
            'company_applications' => CompanyApplication::query()
                ->with('company')
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (CompanyApplication $a) => [
                    'company' => $a->company->name,
                    'message' => $a->getAttribute('message'),
                    'status' => $a->getAttribute('status'),
                    'created_at' => $a->created_at?->toIso8601String(),
                    'decided_at' => $a->getAttribute('decided_at')?->toIso8601String(),
                ])
                ->all(),
            // Kudos and comments the driver gave on jobs (#4646).
            'kudos_given' => DeliveryKudos::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (DeliveryKudos $k) => [
                    'job' => route('jobs.show', $k->delivery_id),
                    'created_at' => $k->created_at?->toIso8601String(),
                ])
                ->all(),
            'comments' => DeliveryComment::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (DeliveryComment $c) => [
                    'job' => route('jobs.show', $c->delivery_id),
                    'body' => $c->body,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])
                ->all(),
            // Follows (#4647): who the driver follows, and how many follow them.
            'following' => $user->following()
                ->orderBy('follows.id')
                ->get()
                ->map(fn (User $u) => [
                    'username' => $u->username,
                    'name' => $u->name,
                    'since' => $u->getRelationValue('pivot')?->getAttribute('created_at')?->toIso8601String(),
                ])
                ->all(),
            'followers' => $user->followers()->count(),
            'home_feed' => $user->home_feed,
            // Company events (#4648): answers given and events created.
            'event_answers' => EventRsvp::query()
                ->with('event')
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (EventRsvp $r) => [
                    'event' => $r->event->title,
                    'starts_at' => $r->event->starts_at->toIso8601String(),
                    'answer' => $r->status->value,
                    'updated_at' => $r->updated_at?->toIso8601String(),
                ])
                ->all(),
            'events_created' => CompanyEvent::query()
                ->where('created_by', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (CompanyEvent $e) => [
                    'title' => $e->title,
                    'starts_at' => $e->starts_at->toIso8601String(),
                    'created_at' => $e->created_at?->toIso8601String(),
                ])
                ->all(),
            'email_notifications_off' => $user->mail_opt_outs ?? [],
            'notifications' => $user->notifications()
                ->get()
                ->map(fn ($n) => [
                    'message' => $n->data['message'] ?? null,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'read_at' => $n->read_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'linked_apps' => $user->tokens()
                ->orderBy('id')
                ->get()
                ->map(fn ($t) => [
                    'name' => $t->name,
                    'created_at' => $t->created_at?->toIso8601String(),
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                ])
                ->all(),
            'passkeys' => $user->passkeys()
                ->orderBy('id')
                ->get(['name', 'created_at', 'last_used_at'])
                ->map(fn ($p) => [
                    'name' => $p->name,
                    'created_at' => $p->created_at?->toIso8601String(),
                    'last_used_at' => $p->last_used_at?->toIso8601String(),
                ])
                ->all(),
            'live_status' => $user->liveStatus === null
                ? null
                : collect($user->liveStatus->attributesToArray())->except(['user_id'])->all(),
            'jobs' => $user->deliveries()
                ->orderBy('id')
                ->get()
                ->map(fn (Delivery $d) => collect($d->attributesToArray())
                    ->except(['id', 'user_id', 'company_id'])
                    ->all())
                ->all(),
        ];
    }
}
