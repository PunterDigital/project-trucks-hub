<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Enums\RsvpStatus;
use App\Http\Controllers\Settings\PrivacyController;
use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\EventRsvp;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Company events and convoys (#4648). */
class CompanyEventTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User, User, User} company, owner, manager, driver */
    private function company(): array
    {
        $company = Company::factory()->create(['name' => 'Convoy Club', 'tag' => 'CVY']);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        $company->members()->create(['user_id' => $manager->id, 'role' => CompanyRole::Manager]);
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        return [$company, $owner, $manager, $driver];
    }

    /** @param array<string, mixed> $overrides */
    private function event(Company $company, array $overrides = []): CompanyEvent
    {
        $event = new CompanyEvent([
            'title' => 'Friday convoy',
            'starts_at' => now()->addDays(2),
            'game' => 'ets2',
            'start_city' => 'Calais',
            'dest_city' => 'Duisburg',
            ...$overrides,
        ]);
        $event->company_id = $company->id;
        $event->save();

        return $event;
    }

    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return [
            'title' => 'Friday convoy',
            'starts_at' => '2030-01-04T19:30:00.000Z',
            'game' => 'ets2',
            'server' => 'Simulation 1',
            'start_city' => 'Calais',
            'start_company' => 'Tradeaux',
            'dest_city' => 'Duisburg',
            'dest_company' => '',
            'route_notes' => "Meet at the docks.\nFuel at Brussel.",
            'max_drivers' => '12',
            'open' => '1',
            ...$overrides,
        ];
    }

    public function test_owners_and_managers_create_events_drivers_cannot(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();

        $this->actingAs($driver)->get(route('companies.events.create', $company))->assertForbidden();
        $this->actingAs($driver)->post(route('companies.events.store', $company), $this->form())->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('companies.events.store', $company), $this->form())->assertForbidden();

        $this->actingAs($manager)->get(route('companies.events.create', $company))->assertOk();
        $response = $this->actingAs($manager)->post(route('companies.events.store', $company), $this->form());

        $event = CompanyEvent::query()->sole();
        $response->assertRedirect(route('events.show', $event));
        $this->assertSame('2030-01-04 19:30:00', $event->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($manager->id, $event->created_by);
        $this->assertSame(12, $event->max_drivers);
        $this->assertTrue($event->open);
        $this->assertNull($event->dest_company);
        $this->assertSame("Meet at the docks.\nFuel at Brussel.", $event->route_notes);

        $this->actingAs($owner)->post(route('companies.events.store', $company), $this->form(['open' => null, 'max_drivers' => '']));
        $this->assertFalse(CompanyEvent::query()->latest('id')->firstOrFail()->open);
    }

    public function test_the_start_time_must_be_in_the_future_and_fields_are_checked(): void
    {
        [$company, $owner] = $this->company();

        $this->actingAs($owner)->post(route('companies.events.store', $company), $this->form([
            'starts_at' => now()->subHour()->toIso8601String(),
            'game' => 'forza',
            'title' => '',
            'max_drivers' => '0',
        ]))->assertSessionHasErrors(['starts_at', 'game', 'title', 'max_drivers']);
        $this->assertSame(0, CompanyEvent::query()->count());
    }

    public function test_editing_keeps_a_past_start_time_but_cannot_move_it_into_the_past(): void
    {
        [$company, $owner] = $this->company();
        $event = $this->event($company);

        $this->actingAs($owner)->get(route('events.edit', $event))->assertOk();
        $this->actingAs($owner)->put(route('events.update', $event), $this->form([
            'title' => 'Saturday convoy',
            'starts_at' => $event->starts_at->toIso8601String(),
        ]))->assertRedirect(route('events.show', $event));
        $this->assertSame('Saturday convoy', $event->fresh()?->title);

        $this->actingAs($owner)->put(route('events.update', $event), $this->form([
            'starts_at' => now()->subDay()->toIso8601String(),
        ]))->assertSessionHasErrors('starts_at');
    }

    public function test_members_answer_and_outsiders_only_when_the_event_is_open(): void
    {
        [$company, , , $driver] = $this->company();
        $closed = $this->event($company);
        $open = $this->event($company, ['open' => true]);
        $outsider = User::factory()->create();

        $this->actingAs($driver)->post(route('events.rsvp', $closed), ['status' => 'going'])->assertRedirect();
        $this->actingAs($driver)->post(route('events.rsvp', $closed), ['status' => 'maybe'])->assertRedirect();
        $this->assertSame(RsvpStatus::Maybe, EventRsvp::query()->sole()->status);

        $this->actingAs($outsider)->post(route('events.rsvp', $closed), ['status' => 'going'])->assertForbidden();
        $this->actingAs($outsider)->get(route('events.show', $closed))->assertInertia(fn ($page) => $page
            ->where('viewer.canAnswer', false)
            ->where('viewer.signedIn', true));
        $this->actingAs($outsider)->post(route('events.rsvp', $open), ['status' => 'going'])->assertRedirect();
        $this->actingAs($outsider)->post(route('events.rsvp', $open), ['status' => 'whenever'])->assertSessionHasErrors('status');

        $this->asGuest()->post(route('events.rsvp', $open), ['status' => 'going'])->assertRedirect(route('login'));
        $this->assertSame(2, EventRsvp::query()->count());
    }

    public function test_a_full_event_refuses_new_drivers_going_but_takes_maybes(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $event = $this->event($company, ['max_drivers' => 1]);

        $this->actingAs($driver)->post(route('events.rsvp', $event), ['status' => 'going']);
        $this->actingAs($manager)->post(route('events.rsvp', $event), ['status' => 'going']);
        $this->actingAs($manager)->post(route('events.rsvp', $event), ['status' => 'maybe']);
        // Already going: answering going again is fine.
        $this->actingAs($driver)->post(route('events.rsvp', $event), ['status' => 'going']);

        $this->assertSame(RsvpStatus::Going, EventRsvp::query()->where('user_id', $driver->id)->value('status'));
        $this->assertSame(RsvpStatus::Maybe, EventRsvp::query()->where('user_id', $manager->id)->value('status'));
        $this->actingAs($owner)->get(route('events.show', $event))->assertInertia(fn ($page) => $page
            ->where('event.full', true)
            ->where('event.going', 1)
            ->has('attendees.going', 1)
            ->has('attendees.maybe', 1));
    }

    public function test_cancelling_tells_going_and_maybe_and_closes_answers(): void
    {
        Notification::fake();
        [$company, $owner, $manager, $driver] = $this->company();
        $event = $this->event($company);
        $event->rsvps()->create(['user_id' => $driver->id, 'status' => RsvpStatus::Going]);
        $event->rsvps()->create(['user_id' => $manager->id, 'status' => RsvpStatus::NotGoing]);

        $this->actingAs($driver)->post(route('events.cancel', $event))->assertForbidden();
        $this->actingAs($owner)->post(route('events.cancel', $event))->assertRedirect(route('events.show', $event));

        $this->assertTrue($event->fresh()?->isCancelled());
        Notification::assertSentTo($driver, HubNotice::class, fn (HubNotice $n) => $n->notice === NoticeType::EventCancelled
            && str_starts_with($n->message, 'Convoy Club cancelled “Friday convoy”')
            && $n->via($driver) === ['database']);
        Notification::assertNotSentTo($manager, HubNotice::class);

        $this->actingAs($driver)->post(route('events.rsvp', $event), ['status' => 'maybe']);
        $this->assertSame(RsvpStatus::Going, $event->rsvps()->where('user_id', $driver->id)->value('status'));
        $this->actingAs($owner)->get(route('events.edit', $event))->assertNotFound();

        // Off the upcoming lists.
        $this->asGuest()->get(route('events.index'))->assertInertia(fn ($page) => $page->has('events.data', 0));
    }

    public function test_the_lists_show_upcoming_events_only(): void
    {
        [$company] = $this->company();
        $this->event($company, ['title' => 'Later', 'starts_at' => now()->addDays(5)]);
        $this->event($company, ['title' => 'Sooner', 'starts_at' => now()->addDay()]);
        $this->event($company, ['title' => 'Under way', 'starts_at' => now()->subHour()]);
        $this->event($company, ['title' => 'Last week', 'starts_at' => now()->subWeek()]);

        $this->get(route('events.index'))->assertInertia(fn ($page) => $page
            ->component('events/Index')
            ->has('events.data', 3)
            ->where('events.data.0.title', 'Under way')
            ->where('events.data.1.title', 'Sooner')
            ->where('events.data.2.company.tag', 'CVY'));
        $this->get(route('companies.show', $company))->assertInertia(fn ($page) => $page
            ->has('events', 3)
            ->where('events.2.title', 'Later'));
    }

    public function test_private_drivers_show_as_private_to_outsiders(): void
    {
        [$company, , , $driver] = $this->company();
        $driver->forceFill(['profile_private' => true])->save();
        $event = $this->event($company);
        $event->rsvps()->create(['user_id' => $driver->id, 'status' => RsvpStatus::Going]);

        $this->get(route('events.show', $event))->assertInertia(fn ($page) => $page
            ->where('attendees.going.0.name', 'Private driver')
            ->where('attendees.going.0.username', null));
        $this->actingAs($driver)->get(route('events.show', $event))->assertInertia(fn ($page) => $page
            ->where('attendees.going.0.username', $driver->username)
            ->where('viewer.rsvp', 'going'));
    }

    public function test_the_ics_file_is_a_valid_calendar_entry(): void
    {
        [$company] = $this->company();
        $event = $this->event($company, [
            'title' => 'Night run, part 2; bring snacks',
            'starts_at' => '2030-01-04 19:30:00',
            'route_notes' => "Line one\nLine two ".str_repeat('long ', 30),
        ]);

        $response = $this->get(route('events.calendar', $event))->assertOk();
        $this->assertStringStartsWith('text/calendar', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="event-'.$event->id.'.ics"', (string) $response->headers->get('Content-Disposition'));

        $ics = (string) $response->getContent();
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20300104T193000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20300104T213000Z\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:[CVY] Night run\\, part 2\\; bring snacks', $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), $line);
        }
        // Unfolded, the notes keep their line break as \n.
        $this->assertStringContainsString('Line one\\nLine two', str_replace("\r\n ", '', $ics));
    }

    public function test_the_export_lists_answers_and_events_and_account_deletion_removes_answers(): void
    {
        [$company, $owner, , $driver] = $this->company();
        $this->actingAs($owner)->post(route('companies.events.store', $company), $this->form());
        $event = CompanyEvent::query()->sole();
        $event->rsvps()->create(['user_id' => $driver->id, 'status' => RsvpStatus::Maybe]);

        $this->assertSame('maybe', PrivacyController::exportFor($driver)['event_answers'][0]['answer']);
        $this->assertSame('Friday convoy', PrivacyController::exportFor($owner)['events_created'][0]['title']);

        $driver->delete();
        $owner->delete();
        $this->assertSame(0, EventRsvp::query()->count());
        $this->assertNull($event->fresh()?->created_by);
    }
}
