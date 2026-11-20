<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\Delivery;
use App\Models\User;
use App\Support\ImageStore;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Search tags, sitemap.xml and robots.txt (#5149). */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ImageStore::DISK);
        // /download asks GitHub for the latest release; no release here.
        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        config(['app.url' => 'https://hub.example.test', 'app.name' => 'Project Trucks']);
    }

    private function title(TestResponse $response): string
    {
        preg_match('#<title[^>]*>(.*?)</title>#s', (string) $response->getContent(), $m);

        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    }

    private function meta(TestResponse $response, string $name): ?string
    {
        preg_match('#<meta name="'.preg_quote($name, '#').'" content="([^"]*)"#', (string) $response->getContent(), $m);

        return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    private function canonical(TestResponse $response): ?string
    {
        preg_match('#<link rel="canonical" href="([^"]*)"#', (string) $response->getContent(), $m);

        return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(TestResponse $response): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', (string) $response->getContent(), $m);

        return array_map(fn (string $json) => (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR), $m[1]);
    }

    private function event(Company $company): CompanyEvent
    {
        $event = new CompanyEvent([
            'title' => 'Friday convoy', 'starts_at' => now()->addDays(2), 'game' => 'ets2',
            'start_city' => 'Calais', 'dest_city' => 'Duisburg',
        ]);
        $event->company_id = $company->id;
        $event->save();

        return $event;
    }

    /** @return list<string> */
    private function sitemapUrls(): array
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);

        $urls = [];
        foreach ($xml->url as $url) {
            $urls[] = (string) $url->loc;
        }

        return $urls;
    }

    public function test_every_static_public_page_has_its_own_title_description_and_canonical(): void
    {
        $paths = [
            'home' => '/', 'leaderboards' => '/leaderboards', 'map' => '/map', 'download' => '/download',
            'drivers.index' => '/drivers', 'companies.index' => '/companies', 'events.index' => '/events',
            'privacy' => '/privacy', 'terms' => '/terms',
        ];

        $titles = [];
        foreach ($paths as $name => $path) {
            $response = $this->get($path)->assertOk();
            [$title, $description] = Seo::PAGES[$name];

            $this->assertSame($title.' - Project Trucks', $this->title($response), $path);
            $this->assertSame($description, $this->meta($response, 'description'), $path);
            $this->assertLessThanOrEqual(160, mb_strlen($description), $path);
            $this->assertSame('https://hub.example.test'.($path === '/' ? '/' : $path), $this->canonical($response), $path);
            $this->assertNull($this->meta($response, 'robots'), $path);
            $titles[] = $title;
        }

        $this->assertSame($titles, array_unique($titles));
    }

    public function test_the_canonical_drops_query_strings_but_keeps_a_page_number(): void
    {
        $this->assertSame('https://hub.example.test/leaderboards', $this->canonical($this->get('/leaderboards?period=week&utm_source=x')));
        $this->assertSame('https://hub.example.test/drivers?page=2', $this->canonical($this->get('/drivers?page=2')));
        $this->assertSame('https://hub.example.test/drivers', $this->canonical($this->get('/drivers?page=1')));
    }

    public function test_item_pages_use_the_link_preview_title_and_private_ones_are_noindex(): void
    {
        $olga = User::factory()->create(['name' => 'Olga Open', 'username' => 'olga-open']);
        $pia = User::factory()->create(['name' => 'Pia Private', 'username' => 'pia-private', 'profile_private' => true]);
        $company = Company::factory()->create(['name' => 'Nordic Haulage', 'tag' => 'NRD']);

        $profile = $this->get('/drivers/olga-open')->assertOk();
        $this->assertSame('Olga Open - Project Trucks', $this->title($profile));
        $this->assertSame('https://hub.example.test/drivers/olga-open', $this->canonical($profile));
        $this->assertNotNull($this->meta($profile, 'description'));
        $this->assertNull($this->meta($profile, 'robots'));

        $club = $this->get('/companies/'.$company->slug)->assertOk();
        $this->assertSame('Nordic Haulage [NRD] - Project Trucks', $this->title($club));

        $private = $this->get('/drivers/pia-private');
        $this->assertSame('noindex', $this->meta($private, 'robots'));
        $this->assertNull($this->canonical($private));
        $this->assertStringNotContainsString('Pia Private - Project Trucks', (string) $private->getContent());

        // A job a guest can't see is a 404, which search engines drop anyway.
        $hiddenJob = Delivery::factory()->for($pia)->create();
        $this->get('/jobs/'.$hiddenJob->id)->assertNotFound();
        $this->assertNotNull($olga);
    }

    public function test_signed_in_only_and_auth_pages_are_noindex(): void
    {
        $this->assertSame('noindex', $this->meta($this->get('/login')->assertOk(), 'robots'));
        $this->assertSame('noindex', $this->meta($this->get('/register')->assertOk(), 'robots'));

        $driver = User::factory()->create();
        $response = $this->actingAs($driver)->get('/dashboard')->assertOk();
        $this->assertSame('noindex', $this->meta($response, 'robots'));
        $this->assertNull($this->canonical($response));
    }

    public function test_home_and_download_have_structured_data(): void
    {
        [$site] = $this->jsonLd($this->get('/'));
        $this->assertSame(['https://schema.org', 'WebSite', 'Project Trucks', 'https://hub.example.test/'], [$site['@context'], $site['@type'], $site['name'], $site['url']]);

        [$app] = $this->jsonLd($this->get('/download'));
        $this->assertSame('SoftwareApplication', $app['@type']);
        $this->assertSame('ProjectTrucks', $app['name']);
        $this->assertSame('Windows', $app['operatingSystem']);
        $this->assertSame('https://hub.example.test/download/windows', $app['downloadUrl']);

        $this->assertSame([], $this->jsonLd($this->get('/leaderboards')));
    }

    public function test_structured_data_cannot_close_its_script_tag(): void
    {
        $json = Seo::encode(['name' => '</script><script>alert(1)</script>']);

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertSame('</script><script>alert(1)</script>', json_decode($json, true)['name']);
    }

    public function test_the_sitemap_lists_public_pages_drivers_companies_and_events_only(): void
    {
        User::factory()->create(['username' => 'olga-open']);
        User::factory()->create(['username' => 'pia-private', 'profile_private' => true]);
        User::factory()->create(['username' => 'susie-suspended', 'suspended_at' => now()]);
        $company = Company::factory()->create(['slug' => 'nordic-haulage']);
        $event = $this->event($company);
        $cancelled = $this->event($company);
        $cancelled->cancelled_at = now();
        $cancelled->save();
        $job = Delivery::factory()->create();

        $urls = $this->sitemapUrls();

        foreach (['/', '/download', '/leaderboards', '/map', '/drivers', '/companies', '/events', '/privacy', '/terms'] as $path) {
            $this->assertContains('https://hub.example.test'.$path, $urls);
        }
        $this->assertContains('https://hub.example.test/drivers/olga-open', $urls);
        $this->assertContains('https://hub.example.test/companies/nordic-haulage', $urls);
        $this->assertContains('https://hub.example.test/events/'.$event->id, $urls);

        $this->assertNotContains('https://hub.example.test/drivers/pia-private', $urls);
        $this->assertNotContains('https://hub.example.test/drivers/susie-suspended', $urls);
        $this->assertNotContains('https://hub.example.test/events/'.$cancelled->id, $urls);
        $this->assertNotContains('https://hub.example.test/jobs/'.$job->id, $urls);
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://hub.example.test/', $url);
        }
    }

    public function test_the_sitemap_sets_no_cookie(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertCookieMissing((string) config('session.cookie'));
    }

    public function test_robots_txt_points_at_the_sitemap_and_blocks_private_areas(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('#^Sitemap: https://[a-z0-9.-]+/sitemap\.xml$#m', $robots);
        foreach (['/dashboard', '/settings', '/link', '/admin'] as $path) {
            $this->assertStringContainsString("Disallow: {$path}\n", $robots);
        }
        $this->assertDoesNotMatchRegularExpression('#^Disallow: /\s*$#m', $robots);
    }
}
