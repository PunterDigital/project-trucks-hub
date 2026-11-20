<?php

namespace Tests\Feature;

use App\Support\Guides;
use App\Support\ImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Help guides at /guides (#5151). */
class GuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ImageStore::DISK);
        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        config(['app.url' => 'https://hub.example.test', 'app.name' => 'Project Trucks']);
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(TestResponse $response): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', (string) $response->getContent(), $m);

        return array_map(fn (string $json) => (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR), $m[1]);
    }

    public function test_there_are_guides_and_each_one_is_complete(): void
    {
        $guides = Guides::all();
        $this->assertGreaterThanOrEqual(6, count($guides));

        $titles = [];
        foreach ($guides as $guide) {
            $slug = $guide['slug'];
            $this->assertNotSame('', trim($guide['title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen($guide['title']), "{$slug}: title too long for search results");
            $this->assertGreaterThanOrEqual(70, mb_strlen($guide['description']), $slug);
            $this->assertLessThanOrEqual(160, mb_strlen($guide['description']), "{$slug}: description over 160 characters");
            $this->assertNotSame('', trim($guide['answer']), $slug);
            $this->assertGreaterThanOrEqual(3, count($guide['faq']), "{$slug}: needs at least three questions");
            foreach ($guide['faq'] as [$question, $answer]) {
                $this->assertStringEndsWith('?', $question, $slug);
                $this->assertNotSame('', trim($answer), $slug);
            }
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $guide['updated'], $slug);
            $titles[] = $guide['title'];
        }

        $this->assertSame(count($titles), count(array_unique($titles)), 'every guide has its own title');
    }

    public function test_the_index_lists_every_guide_in_order(): void
    {
        $this->get('/guides')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('guides/Index')
                ->where('guides', array_map(Guides::summary(...), Guides::all())));
    }

    public function test_each_guide_has_its_search_tags_and_faq_data(): void
    {
        foreach (Guides::all() as $guide) {
            $url = 'https://hub.example.test/guides/'.$guide['slug'];
            $response = $this->get('/guides/'.$guide['slug'])->assertOk();
            $html = (string) $response->getContent();

            $this->assertStringContainsString('<title>'.e($guide['title']).' - Project Trucks</title>', $html);
            $this->assertStringContainsString('<meta name="description" content="'.e($guide['description']).'">', $html);
            $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
            $this->assertStringNotContainsString('name="robots"', $html);

            [$article, $faq] = $this->jsonLd($response);
            $this->assertSame('Article', $article['@type']);
            $this->assertSame($guide['title'], $article['headline']);
            $this->assertSame($url, $article['url']);
            $this->assertSame('FAQPage', $faq['@type']);
            $this->assertSame(
                array_map(fn (array $qa) => $qa[0], $guide['faq']),
                array_map(fn (array $q) => $q['name'], $faq['mainEntity']),
            );
            $this->assertSame(
                array_map(fn (array $qa) => $qa[1], $guide['faq']),
                array_map(fn (array $q) => $q['acceptedAnswer']['text'], $faq['mainEntity']),
            );

            $response->assertInertia(fn (Assert $page) => $page
                ->component('guides/Show')
                ->where('guide.slug', $guide['slug'])
                ->where('guide.title', $guide['title'])
                ->has('guide.faq', count($guide['faq']))
                ->has('others', count(Guides::all()) - 1));
        }
    }

    public function test_an_unknown_guide_is_a_404(): void
    {
        $this->get('/guides/no-such-guide')->assertNotFound();
        $this->get('/guides/Not_A_Slug')->assertNotFound();
    }

    public function test_guide_bodies_link_only_to_pages_that_exist(): void
    {
        foreach (Guides::all() as $guide) {
            $html = Guides::page($guide)['html'];
            $this->assertStringNotContainsString('<script', $html, $guide['slug']);

            preg_match_all('#href="([^"]+)"#', $html, $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES);
                if (str_starts_with($href, '/')) {
                    $status = $this->get(strtok($href, '#'))->status();
                    $this->assertContains($status, [200, 302], "{$guide['slug']} links to {$href} ({$status})");
                } else {
                    $this->assertStringStartsWith('https://', $href, "{$guide['slug']}: {$href}");
                }
            }
        }
    }

    public function test_guides_are_in_the_sitemap(): void
    {
        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>https://hub.example.test/guides</loc>', $xml);
        foreach (Guides::all() as $guide) {
            $this->assertStringContainsString(
                '<loc>https://hub.example.test/guides/'.$guide['slug'].'</loc><lastmod>'.$guide['updated'].'T00:00:00Z</lastmod>',
                $xml,
            );
        }
    }
}
