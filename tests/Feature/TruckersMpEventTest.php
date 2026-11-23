<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\User;
use App\Support\TruckersMpEvents;
use App\Support\TruckersMpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Company events linked to their TruckersMP event (#5228). No live calls: `Http::fake` only. */
class TruckersMpEventTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://truckersmp.com/events/38846-community-contributor-convoy';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> the recorded API answer (`GET /v2/events/38846`, Nov 23 2026) */
    private function recorded(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/truckersmp/event-38846.json')), true);
    }

    private function fakeApi(): void
    {
        Http::fake(['api.truckersmp.com/v2/events/38846' => Http::response($this->recorded())]);
    }

    /** @return array{Company, User, User} company, manager, driver */
    private function company(): array
    {
        $company = Company::factory()->create(['name' => 'Convoy Club', 'tag' => 'CVY']);
        $manager = User::factory()->create();
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $manager->id, 'role' => CompanyRole::Manager]);
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        return [$company, $manager, $driver];
    }

    public function test_links_and_ids_are_read_and_anything_else_is_refused(): void
    {
        foreach ([
            self::LINK,
            'https://truckersmp.com/events/38846',
            'https://www.truckersmp.com/events/38846-community-contributor-convoy/',
            'truckersmp.com/events/38846?tab=attendees',
            '38846',
            '  38846 ',
        ] as $input) {
            $this->assertSame(38846, TruckersMpEvents::parseId($input), $input);
        }

        foreach ([
            null,
            '',
            'https://evil.example/events/38846',
            'https://truckersmp.com.evil.example/events/38846',
            'https://truckersmp.com/vtc/38846',
            'https://truckersmp.com/events/',
            '0',
            'abc',
        ] as $input) {
            $this->assertNull(TruckersMpEvents::parseId($input), (string) $input);
        }
    }

    public function test_the_recorded_event_fills_the_form(): void
    {
        $fields = TruckersMpEvents::parse($this->recorded()['response']);

        $this->assertSame([
            'truckersmp_url' => 'https://truckersmp.com/events/38846',
            'title' => 'Community Contributor Convoy',
            'starts_at' => '2026-12-06T19:00:00+00:00',
            'game' => 'ets2',
            'server' => 'Event Server',
            'start_city' => 'Kalamata',
            'start_company' => 'Assigned slots',
            'dest_city' => 'Ioannina',
            'dest_company' => 'City',
            'route_image_url' => 'https://static.truckersmp.com/images/event/map/38846.1794227679.jpg',
        ], $fields);
    }

    public function test_odd_events_are_cut_to_the_form_or_left_blank(): void
    {
        $event = $this->recorded()['response'];
        $event['name'] = str_repeat('Long name ', 20);
        $event['game'] = 'PROMODS';
        $event['map'] = 'https://evil.example/map.jpg';
        $event['departure'] = null;
        $event['start_at'] = 'not a date';

        $fields = TruckersMpEvents::parse($event);

        $this->assertLessThanOrEqual(120, mb_strlen($fields['title']));
        $this->assertNull($fields['game']);
        $this->assertNull($fields['route_image_url']);
        $this->assertNull($fields['start_city']);
        $this->assertNull($fields['starts_at']);

        $this->expectException(TruckersMpException::class);
        TruckersMpEvents::parse(['id' => 1]);
    }

    public function test_a_manager_fills_from_truckersmp_and_the_answer_is_cached(): void
    {
        [$company, $manager] = $this->company();
        $this->fakeApi();

        $url = route('companies.events.truckersmp', ['company' => $company, 'url' => self::LINK]);
        $this->actingAs($manager)->getJson($url)
            ->assertOk()
            ->assertJsonPath('fields.title', 'Community Contributor Convoy')
            ->assertJsonPath('fields.start_city', 'Kalamata')
            ->assertJsonPath('fields.truckersmp_url', 'https://truckersmp.com/events/38846');
        $this->actingAs($manager)->getJson($url)->assertOk();

        // One call: the second came from the 10-minute cache. Server-side, no key, a 5 s timeout.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.truckersmp.com/v2/events/38846'
            && ! $r->hasHeader('Authorization')
            && str_starts_with($r->header('User-Agent')[0] ?? '', 'ProjectTrucksHub/'));
        $this->assertIsArray(Cache::get(TruckersMpEvents::cacheKey(38846)));
    }

    public function test_only_managers_can_look_up_and_bad_links_never_call_truckersmp(): void
    {
        [$company, $manager, $driver] = $this->company();

        $this->actingAs($driver)->getJson(route('companies.events.truckersmp', ['company' => $company, 'url' => self::LINK]))->assertForbidden();
        $this->actingAs($manager)->getJson(route('companies.events.truckersmp', ['company' => $company, 'url' => 'https://evil.example/events/1']))
            ->assertOk()->assertJsonPath('fields', null)
            ->assertJsonPath('message', 'That isn’t a TruckersMP event link. Copy it from the event’s page, like https://truckersmp.com/events/12345.');

        Http::assertNothingSent();
    }

    public function test_an_outage_a_missing_event_or_junk_shows_a_message(): void
    {
        [$company, $manager] = $this->company();
        $url = fn (int $id) => route('companies.events.truckersmp', ['company' => $company, 'url' => 'https://truckersmp.com/events/'.$id]);

        Http::fake([
            'api.truckersmp.com/v2/events/1' => Http::failedConnection('timed out'),
            'api.truckersmp.com/v2/events/2' => Http::response(['error' => true, 'descriptor' => 'Page not found'], 404),
            'api.truckersmp.com/v2/events/3' => Http::response('<!DOCTYPE html><title>Just a moment...</title>', 403),
            'api.truckersmp.com/v2/events/4' => Http::response('', 500),
        ]);

        $this->actingAs($manager)->getJson($url(1))->assertOk()->assertJsonPath('fields', null)->assertJsonPath('message', 'TruckersMP didn’t answer. Try again in a minute, or fill the form in yourself.');
        $this->actingAs($manager)->getJson($url(2))->assertOk()->assertJsonPath('fields', null)->assertJsonPath('message', 'TruckersMP has no event with that link.');
        $this->actingAs($manager)->getJson($url(3))->assertOk()->assertJsonPath('fields', null)->assertJsonPath('message', 'TruckersMP didn’t answer. Try again in a minute, or fill the form in yourself.');
        $this->actingAs($manager)->getJson($url(4))->assertOk()->assertJsonPath('fields', null);

        // Failures aren't cached: the next try asks again.
        $this->assertNull(Cache::get(TruckersMpEvents::cacheKey(1)));
    }

    public function test_the_event_stores_and_shows_its_truckersmp_link_and_route_image(): void
    {
        [$company, $manager] = $this->company();

        $this->actingAs($manager)->post(route('companies.events.store', $company), [
            'title' => 'Community Contributor Convoy',
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'game' => 'ets2',
            'start_city' => 'Kalamata',
            'dest_city' => 'Ioannina',
            'truckersmp_url' => self::LINK,
            'route_image_url' => 'https://static.truckersmp.com/images/event/map/38846.1794227679.jpg',
        ])->assertRedirect();

        $event = CompanyEvent::query()->sole();
        $this->assertSame(38846, $event->truckersmp_event_id);

        $this->get(route('events.show', $event))->assertInertia(fn ($page) => $page
            ->where('event.truckersmp_url', 'https://truckersmp.com/events/38846')
            ->where('event.route_image_url', 'https://static.truckersmp.com/images/event/map/38846.1794227679.jpg'));
        $this->actingAs($manager)->get(route('events.edit', $event))->assertInertia(fn ($page) => $page
            ->where('event.truckersmp_url', 'https://truckersmp.com/events/38846'));

        // Clearing both fields unlinks it.
        $this->actingAs($manager)->put(route('events.update', $event), [
            'title' => 'Community Contributor Convoy',
            'starts_at' => $event->starts_at->toIso8601String(),
            'game' => 'ets2',
            'start_city' => 'Kalamata',
            'dest_city' => 'Ioannina',
            'truckersmp_url' => '',
            'route_image_url' => '',
        ])->assertRedirect();
        $this->assertNull($event->fresh()->truckersmp_event_id);
        $this->assertNull($event->fresh()->route_image_url);
    }

    public function test_links_and_images_from_elsewhere_are_refused(): void
    {
        [$company, $manager] = $this->company();

        $this->actingAs($manager)->post(route('companies.events.store', $company), [
            'title' => 'Convoy',
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'game' => 'ets2',
            'start_city' => 'Kalamata',
            'dest_city' => 'Ioannina',
            'truckersmp_url' => 'https://evil.example/events/1',
            'route_image_url' => 'https://evil.example/track.gif',
        ])->assertSessionHasErrors(['truckersmp_url', 'route_image_url']);

        $this->assertSame(0, CompanyEvent::query()->count());
    }
}
