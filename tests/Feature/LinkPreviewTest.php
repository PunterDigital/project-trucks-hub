<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\RsvpStatus;
use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\Delivery;
use App\Models\User;
use App\Support\ImageStore;
use App\Support\LinkPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Open Graph tags and generated preview cards (#4652). */
class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ImageStore::DISK);
    }

    /** @return array<string, string> property/name => content */
    private function metaTags(TestResponse $response): array
    {
        preg_match_all('/<meta (?:property|name)="([^"]+)" content="([^"]*)"/', (string) $response->getContent(), $m, PREG_SET_ORDER);

        $tags = [];
        foreach ($m as [, $key, $value]) {
            $tags[$key] = html_entity_decode($value, ENT_QUOTES);
        }

        return $tags;
    }

    private function assertPng(TestResponse $response): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $info = getimagesizefromstring((string) $response->getContent());
        $this->assertNotFalse($info);
        $this->assertSame([LinkPreview::WIDTH, LinkPreview::HEIGHT, 'image/png'], [$info[0], $info[1], $info['mime']]);
    }

    /** The path part of an og:image URL, for a test request. */
    private function imagePath(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_a_public_job_page_has_tags_and_a_card(): void
    {
        $driver = User::factory()->create(['name' => 'Jack Job']);
        $job = Delivery::factory()->for($driver)->create([
            'source_city' => 'Rotterdam', 'dest_city' => 'Nynäshamn', 'cargo_name' => 'Apples', 'cargo_mass_kg' => 12000, 'distance_km' => 812.4,
        ]);

        $tags = $this->metaTags($this->get(route('jobs.show', $job))->assertOk());

        $this->assertSame('Rotterdam → Nynäshamn', $tags['og:title']);
        $this->assertStringContainsString('Apples (12 t)', $tags['og:description']);
        $this->assertStringContainsString('Jack Job', $tags['og:description']);
        $this->assertStringContainsString('812 km', $tags['og:description']);
        $this->assertSame('summary_large_image', $tags['twitter:card']);
        $this->assertSame(route('jobs.show', $job), $tags['og:url']);
        $this->assertMatchesRegularExpression('#/og/job/'.$job->id.'\.png\?v=[0-9a-f]{16}$#', $tags['og:image']);

        $image = $this->get($this->imagePath($tags['og:image']));
        $this->assertPng($image);
        $image->assertHeader('Cache-Control', 'max-age=3600, public');
        $this->assertSame([], $image->headers->getCookies(), 'the card is served without a session');
    }

    public function test_profile_company_and_event_pages_have_tags_and_cards(): void
    {
        $company = Company::factory()->create(['name' => 'Convoy Club', 'tag' => 'CVY', 'description' => "Night runs\nevery Friday"]);
        $owner = User::factory()->create(['name' => 'Eve Events', 'username' => 'eve-events']);
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        Delivery::factory()->for($owner)->create(['company_id' => $company->id, 'distance_km' => 300, 'completed_at' => now()->subHour(), 'started_at' => now()->subHours(6)]);

        $event = new CompanyEvent(['title' => 'Friday convoy', 'starts_at' => now()->addDays(2), 'game' => 'ets2', 'start_city' => 'Calais', 'dest_city' => 'Duisburg', 'max_drivers' => 8]);
        $event->company_id = $company->id;
        $event->save();
        $event->rsvps()->create(['user_id' => $owner->id, 'status' => RsvpStatus::Going]);

        $driver = $this->metaTags($this->get(route('drivers.show', $owner))->assertOk());
        $this->assertSame('Eve Events', $driver['og:title']);
        $this->assertStringContainsString('Convoy Club [CVY]', $driver['og:description']);
        $this->assertStringContainsString('Jobs: 1', $driver['og:description']);

        $club = $this->metaTags($this->get(route('companies.show', $company))->assertOk());
        $this->assertSame('Convoy Club [CVY]', $club['og:title']);
        $this->assertStringContainsString('Night runs every Friday', $club['og:description']);
        $this->assertStringContainsString('Drivers: 1', $club['og:description']);

        $convoy = $this->metaTags($this->get(route('events.show', $event))->assertOk());
        $this->assertSame('Friday convoy', $convoy['og:title']);
        $this->assertStringContainsString('Going: 1 / 8', $convoy['og:description']);

        foreach ([$driver, $club, $convoy] as $tags) {
            $this->assertPng($this->get($this->imagePath($tags['og:image'])));
        }
    }

    public function test_previews_are_built_as_a_guest_sees_the_item(): void
    {
        $company = Company::factory()->create();
        $private = User::factory()->create(['name' => 'Pia Private', 'profile_private' => true]);
        $mate = User::factory()->create();
        $company->members()->create(['user_id' => $private->id, 'role' => CompanyRole::Owner]);
        $company->members()->create(['user_id' => $mate->id, 'role' => CompanyRole::Driver]);
        $privateJob = Delivery::factory()->for($private)->create();
        $hiddenJob = Delivery::factory()->create(['hidden' => true]);

        // The mate can see the profile and the job, but a pasted link must not show them.
        $tags = $this->metaTags($this->actingAs($mate)->get(route('drivers.show', $private))->assertOk());
        $this->assertArrayNotHasKey('og:title', $tags);
        $this->assertArrayHasKey('og:site_name', $tags);
        $this->assertArrayNotHasKey('og:title', $this->metaTags($this->get(route('jobs.show', $privateJob))->assertOk()));

        $this->get('/og/driver/'.$private->id.'.png')->assertNotFound();
        $this->get('/og/job/'.$privateJob->id.'.png')->assertNotFound();
        $this->get('/og/job/'.$hiddenJob->id.'.png')->assertNotFound();
        $this->get('/og/job/999999.png')->assertNotFound();
        $this->get('/og/user/'.$mate->id.'.png')->assertNotFound();
    }

    public function test_suspended_drivers_get_no_preview(): void
    {
        $driver = User::factory()->create(['suspended_at' => now()]);
        $job = Delivery::factory()->for($driver)->create();

        $this->get('/og/driver/'.$driver->id.'.png')->assertNotFound();
        $this->get('/og/job/'.$job->id.'.png')->assertNotFound();
    }

    public function test_a_card_is_drawn_once_and_redrawn_when_it_changes(): void
    {
        $company = Company::factory()->create(['name' => 'Old Name', 'tag' => 'OLD']);
        $disk = Storage::disk(ImageStore::DISK);

        $first = LinkPreview::meta('company', $company->id);
        $this->assertNotNull($first);
        $this->assertPng($this->get($this->imagePath($first['image'])));
        $this->assertCount(1, $disk->files('og/company'));
        $this->assertPng($this->get($this->imagePath($first['image'])));
        $this->assertCount(1, $disk->files('og/company'));

        $company->update(['name' => 'New Name']);
        $second = LinkPreview::meta('company', $company->id);
        $this->assertNotNull($second);
        $this->assertNotSame($first['image'], $second['image']);

        $this->assertPng($this->get($this->imagePath($second['image'])));
        $files = $disk->files('og/company');
        $this->assertCount(1, $files, 'the old card is deleted');
        $this->assertStringContainsString(substr((string) strrchr($second['image'], '='), 1), $files[0]);
    }

    public function test_long_names_and_other_scripts_still_render(): void
    {
        $driver = User::factory()->create(['name' => str_repeat('Щербаков Łódź ', 12)]);
        Delivery::factory()->for($driver)->create(['source_city' => 'Göteborg', 'dest_city' => 'Kraków', 'cargo_name' => str_repeat('Very long cargo name ', 10)]);

        $this->assertPng($this->get('/og/driver/'.$driver->id.'.png'));
        $this->assertPng($this->get('/og/job/'.$driver->deliveries()->value('id').'.png'));
    }

    public function test_other_pages_only_carry_the_site_name(): void
    {
        $tags = $this->metaTags($this->get(route('home'))->assertOk());

        $this->assertSame(config('app.name'), $tags['og:site_name']);
        $this->assertArrayNotHasKey('og:image', $tags);
    }
}
