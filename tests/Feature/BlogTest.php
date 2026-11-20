<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\PostResource;
use App\Models\AdminAuditLog;
use App\Models\Post;
use App\Models\User;
use App\Support\ImageStore;
use App\Support\LinkPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Tests\TestCase;

/** The blog (#5150): public pages, feed, sitemap, safe Markdown, and writing posts in the admin. */
class BlogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ImageStore::DISK);
        config(['app.url' => 'https://hub.example.test', 'app.name' => 'Project Trucks']);
    }

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    public function test_only_published_posts_whose_time_has_come_are_public(): void
    {
        $live = Post::factory()->create(['slug' => 'live-post', 'title' => 'Live post', 'published_at' => now()->subHour()]);
        $draft = Post::factory()->draft()->create(['slug' => 'draft-post', 'title' => 'Draft post']);
        $scheduled = Post::factory()->create(['slug' => 'later-post', 'title' => 'Later post', 'published_at' => now()->addDay()]);

        $this->get('/blog/live-post')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('blog/Show')
                ->where('post.title', 'Live post')
                ->where('preview', false));
        $this->get('/blog/draft-post')->assertNotFound();
        $this->get('/blog/later-post')->assertNotFound();
        $this->get('/blog/no-such-post')->assertNotFound();

        // Staff see drafts too, only on the preview page.
        $this->actingAs($this->staff())->get('/blog/draft-post')->assertNotFound();

        $this->get('/blog')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('blog/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.slug', 'live-post'));

        $this->assertSame([$live->id], Post::query()->published()->pluck('id')->all());
        $this->assertFalse($draft->isPublic());
        $this->assertFalse($scheduled->isPublic());
    }

    public function test_the_list_is_newest_first_and_paginated(): void
    {
        foreach (range(1, Post::PER_PAGE + 2) as $i) {
            Post::factory()->create(['slug' => 'post-'.$i, 'published_at' => now()->subDays(30 - $i)]);
        }

        $this->get('/blog')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', Post::PER_PAGE)
            ->where('posts.data.0.slug', 'post-'.(Post::PER_PAGE + 2))
            ->where('posts.last_page', 2));
        $this->get('/blog?page=2')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 2)
            ->where('posts.data.1.slug', 'post-1'));
    }

    public function test_the_preview_is_for_staff_with_two_factor_only(): void
    {
        $draft = Post::factory()->draft()->create(['slug' => 'secret-plans']);
        $url = route('blog.preview', $draft);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

        $noTwoFactor = User::factory()->create();
        $noTwoFactor->forceFill(['is_staff' => true])->save();
        $this->actingAs($noTwoFactor)->get($url)->assertNotFound();

        $response = $this->actingAs($this->staff())->get($url)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('blog/Show')
                ->where('preview', true)
                ->where('post.status', 'draft')
                ->where('post.is_public', false));
        $this->assertStringContainsString('<meta name="robots" content="noindex">', (string) $response->getContent());
    }

    public function test_markdown_is_rendered_without_raw_html_or_script_links(): void
    {
        $post = Post::factory()->create([
            'slug' => 'tricky',
            'body' => "Hello **drivers**.\n\n<script>alert('x')</script>\n\n<img src=x onerror=alert(1)>\n\n[click](javascript:alert(1)) and [safe](https://example.com)",
        ]);

        $html = $post->bodyHtml();

        $this->assertStringContainsString('<strong>drivers</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<a href="https://example.com">safe</a>', $html);

        $this->get('/blog/tricky')->assertInertia(fn (AssertableInertia $page) => $page->where('post.html', $html));
    }

    public function test_a_post_page_has_its_own_search_tags_article_data_and_preview_card(): void
    {
        $post = Post::factory()->create([
            'slug' => 'convoy-season',
            'title' => 'Convoy season is here',
            'excerpt' => 'Everything new for convoys this winter.',
        ]);

        $html = (string) $this->get('/blog/convoy-season')->getContent();

        $this->assertStringContainsString('<title>Convoy season is here - Project Trucks</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Everything new for convoys this winter.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://hub.example.test/blog/convoy-season">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.e(LinkPreview::meta('post', $post->id)['image'] ?? ''), $html);
        $this->assertStringNotContainsString('name="robots"', $html);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $article = json_decode($m[1][0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('BlogPosting', $article['@type']);
        $this->assertSame('Convoy season is here', $article['headline']);
        $this->assertSame('https://hub.example.test/blog/convoy-season', $article['url']);
        $this->assertSame('Project Trucks', $article['author']['name']);

        $this->get(route('preview-image', ['type' => 'post', 'id' => $post->id]))
            ->assertOk()->assertHeader('Content-Type', 'image/png');

        // A draft has no card at all.
        $draft = Post::factory()->draft()->create();
        $this->assertNull(LinkPreview::meta('post', $draft->id));
        $this->get(route('preview-image', ['type' => 'post', 'id' => $draft->id]))->assertNotFound();

        // The blog list is indexable too.
        $index = (string) $this->get('/blog')->getContent();
        $this->assertStringContainsString('<title>Blog - Project Trucks</title>', $index);
        $this->assertStringContainsString('<link rel="canonical" href="https://hub.example.test/blog">', $index);
    }

    public function test_the_feed_lists_published_posts_only(): void
    {
        Post::factory()->create(['slug' => 'older', 'title' => 'Older & wiser', 'published_at' => now()->subDays(2)]);
        Post::factory()->create(['slug' => 'newer', 'title' => 'Newer', 'published_at' => now()->subDay()]);
        Post::factory()->draft()->create(['slug' => 'hidden-draft']);
        Post::factory()->create(['slug' => 'hidden-later', 'published_at' => now()->addHour()]);

        $response = $this->get('/blog/feed')->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->assertCookieMissing((string) config('session.cookie'));

        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);
        $items = $xml->channel->item;
        $this->assertCount(2, $items);
        $this->assertSame('Newer', (string) $items[0]->title);
        $this->assertSame('Older & wiser', (string) $items[1]->title);
        $this->assertSame('https://hub.example.test/blog/older', (string) $items[1]->link);
    }

    public function test_published_posts_are_in_the_sitemap(): void
    {
        Post::factory()->create(['slug' => 'in-the-map']);
        Post::factory()->draft()->create(['slug' => 'not-in-the-map']);

        $xml = (string) $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('<loc>https://hub.example.test/blog</loc>', $xml);
        $this->assertStringContainsString('<loc>https://hub.example.test/blog/in-the-map</loc>', $xml);
        $this->assertStringNotContainsString('not-in-the-map', $xml);
    }

    public function test_staff_write_a_draft_then_publish_it(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        Livewire::test(CreatePost::class)
            ->fillForm([
                'title' => 'Hello from the Hub',
                'slug' => 'hello-from-the-hub',
                'excerpt' => 'Our first post.',
                'body' => 'Welcome, **drivers**.',
                'status' => PostStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::query()->where('slug', 'hello-from-the-hub')->firstOrFail();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertNull($post->published_at);
        $this->get('/blog/hello-from-the-hub')->assertNotFound();

        Livewire::test(EditPost::class, ['record' => $post->id])
            ->fillForm(['status' => PostStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertTrue($post->isPublic());
        $this->get('/blog/hello-from-the-hub')->assertOk();

        $this->assertSame(
            ['post.created', 'post.published'],
            AdminAuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('Hello from the Hub', AdminAuditLog::query()->latest('id')->value('target_label'));
    }

    public function test_slugs_must_be_unique_well_formed_and_not_reserved(): void
    {
        $this->actingAs($this->staff());
        Post::factory()->create(['slug' => 'taken']);

        foreach (['taken' => 'unique', 'feed' => 'not_in', 'Bad Slug!' => 'regex'] as $slug => $rule) {
            Livewire::test(CreatePost::class)
                ->fillForm(['title' => 'T', 'slug' => $slug, 'excerpt' => 'E', 'body' => 'B', 'status' => 'draft'])
                ->call('create')
                ->assertHasFormErrors(['slug' => $rule]);
        }
    }

    public function test_the_title_fills_an_empty_slug(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(CreatePost::class)
            ->fillForm(['title' => 'Winter Update: Snow & Ice'])
            ->assertSchemaStateSet(['slug' => 'winter-update-snow-ice']);
    }

    public function test_a_cover_is_re_encoded_and_the_old_one_deleted(): void
    {
        $this->actingAs($this->staff());
        $post = Post::factory()->create(['slug' => 'with-cover']);

        Livewire::test(EditPost::class, ['record' => $post->id])
            ->fillForm(['cover_path' => UploadedFile::fake()->image('cover.jpg', 1600, 900)])
            ->call('save')
            ->assertHasNoFormErrors();

        $first = (string) $post->refresh()->cover_path;
        $this->assertMatchesRegularExpression('#^covers/'.ImageStore::FILE_PATTERN.'$#', $first);
        Storage::disk(ImageStore::DISK)->assertExists($first);
        [$width, $height] = (array) getimagesizefromstring((string) Storage::disk(ImageStore::DISK)->get($first));
        $this->assertSame([1200, 630], [$width, $height]);
        $this->get(ImageStore::url($first))->assertOk();

        // Removed in the form: the file goes with it.
        Livewire::test(EditPost::class, ['record' => $post->id])
            ->fillForm(['cover_path' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($post->refresh()->cover_path);
        Storage::disk(ImageStore::DISK)->assertMissing($first);

        Livewire::test(EditPost::class, ['record' => $post->id])
            ->fillForm(['cover_path' => UploadedFile::fake()->image('second.png', 1300, 700)])
            ->call('save')
            ->assertHasNoFormErrors();

        $second = (string) $post->refresh()->cover_path;
        $this->assertNotSame($first, $second);
        Storage::disk(ImageStore::DISK)->assertExists($second);

        $post->delete();
        Storage::disk(ImageStore::DISK)->assertMissing($second);
    }

    public function test_drivers_cannot_reach_the_posts_admin(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(PostResource::getUrl('index'))
            ->assertNotFound();
    }
}
