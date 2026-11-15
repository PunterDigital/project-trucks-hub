<?php

namespace App\Http\Controllers;

use App\Enums\RsvpStatus;
use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\EventRsvp;
use App\Models\User;
use App\Notifications\HubNotice;
use App\Support\DiscordWebhook;
use App\Support\EventCalendar;
use App\Support\LinkPreview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company events and convoys (#4648). Owners and managers create, edit and cancel them;
 * members answer (going / maybe / not going), and anyone signed in can when the event is open.
 */
class CompanyEventController extends Controller
{
    /** Every company's upcoming events. */
    public function index(): Response
    {
        $events = CompanyEvent::query()
            ->upcoming()
            ->with('company')
            ->withCount(['rsvps as going_count' => fn (Builder $q) => $q->where('status', RsvpStatus::Going)])
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CompanyEvent $e) => $e->summary());

        return Inertia::render('events/Index', ['events' => $events]);
    }

    public function show(Request $request, CompanyEvent $event): Response
    {
        $viewer = $request->user();
        $event->load('company');

        $rsvps = $event->rsvps()->with('user.membership.company')->oldest()->oldest('id')->get()
            ->filter(fn (EventRsvp $r) => ! $r->user->isSuspended());
        $people = fn (RsvpStatus $status) => $rsvps
            ->filter(fn (EventRsvp $r) => $r->status === $status)
            ->map(fn (EventRsvp $r) => $r->user->isVisibleTo($viewer)
                ? $r->user->publicSummary()
                : ['name' => __('Private driver'), 'username' => null, 'avatar' => null])
            ->values()
            ->all();

        $mine = $viewer === null ? null : $rsvps->first(fn (EventRsvp $r) => $r->user_id === $viewer->id);

        return Inertia::render('events/Show', [
            'event' => [
                ...$event->summary(),
                'start_company' => $event->start_company,
                'dest_company' => $event->dest_company,
                'route_notes' => $event->route_notes,
                'over' => $event->isOver(),
                'full' => $event->isFull(),
            ],
            'attendees' => [
                'going' => $people(RsvpStatus::Going),
                'maybe' => $people(RsvpStatus::Maybe),
                'not_going' => count($people(RsvpStatus::NotGoing)),
            ],
            'viewer' => [
                'signedIn' => $viewer !== null,
                'canAnswer' => $event->canAnswer($viewer),
                'canManage' => $event->company->roleOf($viewer)?->canManage() ?? false,
                'rsvp' => $mine?->status->value,
            ],
        ])->withViewData('preview', fn () => LinkPreview::meta('event', $event->id));
    }

    public function create(Request $request, Company $company): Response
    {
        $this->authorizeManage($request, $company);

        return Inertia::render('events/Edit', [
            'company' => $company->summary(),
            'event' => null,
            'games' => CompanyEvent::GAMES,
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeManage($request, $company);

        $event = new CompanyEvent($this->validated($request, null));
        $event->company_id = $company->id;
        $event->created_by = $request->user()?->id;
        $event->save();
        DiscordWebhook::eventCreated($event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event created.')]);

        return redirect()->route('events.show', $event);
    }

    public function edit(Request $request, CompanyEvent $event): Response
    {
        $this->authorizeManage($request, $event->company);
        abort_if($event->isCancelled(), 404);

        return Inertia::render('events/Edit', [
            'company' => $event->company->summary(),
            'event' => [
                ...$event->summary(),
                'start_company' => $event->start_company,
                'dest_company' => $event->dest_company,
                'route_notes' => $event->route_notes,
            ],
            'games' => CompanyEvent::GAMES,
        ]);
    }

    public function update(Request $request, CompanyEvent $event): RedirectResponse
    {
        $this->authorizeManage($request, $event->company);
        abort_if($event->isCancelled(), 404);

        $event->update($this->validated($request, $event));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event saved.')]);

        return redirect()->route('events.show', $event);
    }

    public function cancel(Request $request, CompanyEvent $event): RedirectResponse
    {
        $this->authorizeManage($request, $event->company);

        if (! $event->isCancelled()) {
            $event->forceFill(['cancelled_at' => now()])->save();

            // Everyone who said going or maybe hears about it on the bell.
            $event->rsvps()->with('user')
                ->whereIn('status', [RsvpStatus::Going, RsvpStatus::Maybe])
                ->get()
                ->each(fn (EventRsvp $r) => $r->user->notify(HubNotice::eventCancelled($event)));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event cancelled.')]);

        return redirect()->route('events.show', $event);
    }

    public function rsvp(Request $request, CompanyEvent $event): RedirectResponse
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User && $event->canAnswer($viewer), 403);

        $data = $request->validate(['status' => ['required', Rule::enum(RsvpStatus::class)]]);
        $status = RsvpStatus::from($data['status']);

        if ($event->isCancelled() || $event->isOver()) {
            return $this->refuse(__('This event is no longer taking answers.'));
        }

        $current = $event->rsvps()->where('user_id', $viewer->id)->first();
        if ($status === RsvpStatus::Going && $current?->status !== RsvpStatus::Going && $event->isFull()) {
            return $this->refuse(__('This event is full.'));
        }

        $event->rsvps()->updateOrCreate(['user_id' => $viewer->id], ['status' => $status]);

        return back();
    }

    public function calendar(CompanyEvent $event): HttpResponse
    {
        return response(EventCalendar::ics($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="event-'.$event->id.'.ics"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CompanyEvent $event): array
    {
        $startsAt = $request->input('starts_at');
        $unchanged = $event !== null && is_string($startsAt) && strtotime($startsAt) === $event->starts_at->getTimestamp();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date', ...($unchanged ? [] : ['after:now'])],
            'game' => ['required', Rule::in(array_keys(CompanyEvent::GAMES))],
            'server' => ['nullable', 'string', 'max:80'],
            'start_city' => ['required', 'string', 'max:80'],
            'start_company' => ['nullable', 'string', 'max:80'],
            'dest_city' => ['required', 'string', 'max:80'],
            'dest_company' => ['nullable', 'string', 'max:80'],
            'route_notes' => ['nullable', 'string', 'max:2000'],
            'max_drivers' => ['nullable', 'integer', 'min:1', 'max:500'],
            'open' => ['nullable', 'boolean'],
        ], ['starts_at.after' => __('The start time must be in the future.')], ['starts_at' => 'start time']);

        $data['starts_at'] = Carbon::parse((string) $data['starts_at'])->utc();
        $data['open'] = (bool) ($data['open'] ?? false);

        return $data;
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }

    private function authorizeManage(Request $request, Company $company): void
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);
    }
}
