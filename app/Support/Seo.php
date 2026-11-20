<?php

namespace App\Support;

use App\Models\Post;
use Illuminate\Http\Request;

/**
 * Search tags (#5149): the title, description, canonical URL, robots rule and JSON-LD that
 * `app.blade.php` prints for each page. Printed by Blade, so crawlers that don't run
 * JavaScript get them (production runs without SSR, see #5157).
 *
 * The titles must match the `<Head title>` each page sets, or the tab changes once the app
 * loads: `SeoTest` and `seo.spec.ts` compare them. Item pages (a driver, a job, a company,
 * an event) take their title and description from the link preview, built as a guest sees the
 * item; one a guest can't see gets noindex and the generic title.
 */
class Seo
{
    /**
     * Public pages that search engines may index, with the title (without the site name) and
     * the description. Item pages use these only as a fallback.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PAGES = [
        'home' => [
            'ETS2 job tracker, VTCs and leaderboards',
            'Track your Euro Truck Simulator 2 jobs automatically with the free ProjectTrucks app. Driver profiles, virtual trucking companies, a live map and leaderboards.',
        ],
        'leaderboards' => [
            'ETS2 driver and company leaderboards',
            'Who drove furthest this week? Euro Truck Simulator 2 leaderboards for drivers and virtual trucking companies, from jobs tracked by ProjectTrucks.',
        ],
        'map' => [
            'Live ETS2 map',
            'See which Euro Truck Simulator 2 drivers are on the road right now, live on the map.',
        ],
        'download' => [
            'Download ProjectTrucks',
            'Download ProjectTrucks for Windows: a free Euro Truck Simulator 2 job tracker that logs every delivery, your earnings and distance, and syncs them to the Hub.',
        ],
        'drivers.index' => [
            'ETS2 drivers',
            'Browse Euro Truck Simulator 2 drivers on ProjectTrucks Hub: profiles, levels, jobs driven and distance.',
        ],
        'drivers.show' => [
            'Driver profile',
            'A Euro Truck Simulator 2 driver on ProjectTrucks Hub.',
        ],
        'jobs.show' => [
            'Job',
            'A Euro Truck Simulator 2 job tracked by ProjectTrucks.',
        ],
        'companies.index' => [
            'Virtual trucking companies (VTCs)',
            'Find a virtual trucking company (VTC) for Euro Truck Simulator 2, or start your own: members, monthly goals, convoys and company stats.',
        ],
        'companies.show' => [
            'Company',
            'A virtual trucking company (VTC) on ProjectTrucks Hub.',
        ],
        'events.index' => [
            'Convoys and events',
            'Upcoming Euro Truck Simulator 2 convoys and company events. See the route, server and start time, and say if you are coming.',
        ],
        'events.show' => [
            'Event',
            'A Euro Truck Simulator 2 convoy or company event on ProjectTrucks Hub.',
        ],
        'blog.index' => [
            'Blog',
            'News, guides and release notes from ProjectTrucks, the free Euro Truck Simulator 2 job tracker, and the Hub.',
        ],
        'blog.show' => [
            'Blog post',
            'A post on the ProjectTrucks blog.',
        ],
        'privacy' => [
            'Privacy',
            'What ProjectTrucks Hub stores about you, why, and how to export or delete it.',
        ],
        'terms' => [
            'Terms of use',
            'The rules for using ProjectTrucks Hub.',
        ],
    ];

    /** Pages about one item: indexable only when a guest can see it (it has a link preview). */
    public const ITEM_PAGES = ['drivers.show', 'jobs.show', 'companies.show', 'events.show', 'blog.show'];

    /** Static pages in the sitemap, in this order. */
    public const SITEMAP_PAGES = ['home', 'download', 'leaderboards', 'map', 'drivers.index', 'companies.index', 'events.index', 'blog.index', 'privacy', 'terms'];

    /**
     * @param  array{title: string, description: string, url: string, image: string, image_alt: string}|null  $preview
     * @return array{title: string|null, description: string|null, canonical: string|null, index: bool, json_ld: list<array<string, mixed>>}
     */
    public static function forRequest(Request $request, ?array $preview): array
    {
        $name = $request->route()?->getName();
        $page = is_string($name) ? (self::PAGES[$name] ?? null) : null;

        if ($page === null || (in_array($name, self::ITEM_PAGES, true) && $preview === null)) {
            return ['title' => null, 'description' => null, 'canonical' => null, 'index' => false, 'json_ld' => []];
        }

        [$title, $description] = $page;
        if ($preview !== null) {
            $title = $preview['title'];
            $description = $preview['description'];
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => self::canonical($request),
            'index' => true,
            'json_ld' => self::jsonLd((string) $name, $request),
        ];
    }

    /** The page's address on APP_URL's host, without query strings except a page number. */
    public static function canonical(Request $request): string
    {
        $url = self::url($request->path());
        $page = $request->query('page');

        if (is_string($page) && ctype_digit($page) && (int) $page > 1) {
            $url .= '?page='.(int) $page;
        }

        return $url;
    }

    /** An absolute URL on APP_URL, so a domain move only needs the env change. */
    public static function url(string $path): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $path = trim($path, '/');

        return $path === '' ? $base.'/' : $base.'/'.$path;
    }

    /**
     * Structured data as a JSON string that is safe inside a script tag.
     *
     * @param  array<mixed>  $data
     */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array<string, mixed>> */
    private static function jsonLd(string $name, Request $request): array
    {
        $post = $request->route('post');

        return match ($name) {
            'blog.show' => $post instanceof Post ? [self::article($post)] : [],
            'home' => [[
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => (string) config('app.name'),
                'url' => self::url(''),
                'description' => self::PAGES['home'][1],
            ]],
            'download' => [self::softwareApplication()],
            default => [],
        };
    }

    /**
     * A blog post as a schema.org BlogPosting. The author is the site, not the staff member who
     * wrote it.
     *
     * @return array<string, mixed>
     */
    public static function article(Post $post): array
    {
        $site = ['@type' => 'Organization', 'name' => (string) config('app.name'), 'url' => self::url('')];
        $url = self::url('blog/'.$post->slug);

        $article = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post->title,
            'description' => $post->excerpt,
            'url' => $url,
            'mainEntityOfPage' => $url,
            'datePublished' => $post->published_at?->copy()->utc()->toIso8601String(),
            'dateModified' => ($post->updated_at ?? $post->published_at)?->copy()->utc()->toIso8601String(),
            'author' => $site,
            'publisher' => $site,
        ];

        if ($post->cover_path !== null) {
            $article['image'] = self::url((string) ImageStore::url($post->cover_path));
        }

        return $article;
    }

    /** @return array<string, mixed> */
    private static function softwareApplication(): array
    {
        $app = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'ProjectTrucks',
            'description' => self::PAGES['download'][1],
            'applicationCategory' => 'GameApplication',
            'operatingSystem' => 'Windows',
            'url' => self::url('download'),
            'downloadUrl' => self::url('download/windows'),
            'isAccessibleForFree' => true,
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'GBP'],
        ];

        $release = LatestRelease::get();
        if ($release !== null) {
            $app['softwareVersion'] = $release['version'];
        }

        return $app;
    }
}
