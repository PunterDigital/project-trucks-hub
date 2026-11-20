<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use App\Support\ImageStore;
use App\Support\LinkPreview;
use App\Support\Seo;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The blog (#5150): /blog, /blog/{slug}, the RSS feed, and a staff-only preview of any post.
 * Posts are written in the admin panel (`PostResource`). Only published posts whose time has
 * come are public; everything else is a 404, as if it didn't exist.
 */
class BlogController extends Controller
{
    /** How many posts the RSS feed carries. */
    public const FEED_SIZE = 20;

    public function index(): Response
    {
        $posts = Post::query()->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(Post::PER_PAGE)
            ->withQueryString()
            ->through(fn (Post $post) => $post->summary());

        return Inertia::render('blog/Index', ['posts' => $posts]);
    }

    public function show(Post $post): Response
    {
        abort_unless($post->isPublic(), 404);

        return $this->page($post, false)
            ->withViewData('preview', fn () => LinkPreview::meta('post', $post->id));
    }

    /** Staff see any post, draft or future-dated, as readers will. Never indexed. */
    public function preview(Request $request, Post $post): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')), 404);

        return $this->page($post, true);
    }

    public function feed(): HttpResponse
    {
        $posts = Post::query()->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::FEED_SIZE)
            ->get();

        $e = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $name = (string) config('app.name');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'."\n"
            ."<channel>\n"
            .'  <title>'.$e($name.' blog').'</title>'."\n"
            .'  <link>'.$e(Seo::url('blog')).'</link>'."\n"
            .'  <description>'.$e(Seo::PAGES['blog.index'][1]).'</description>'."\n"
            .'  <language>en</language>'."\n"
            .'  <atom:link href="'.$e(Seo::url('blog/feed')).'" rel="self" type="application/rss+xml"/>'."\n";

        if ($posts->isNotEmpty()) {
            $xml .= '  <lastBuildDate>'.$posts->first()->published_at?->copy()->utc()->toRfc2822String().'</lastBuildDate>'."\n";
        }

        foreach ($posts as $post) {
            $url = Seo::url('blog/'.$post->slug);
            $xml .= "  <item>\n"
                .'    <title>'.$e($post->title).'</title>'."\n"
                .'    <link>'.$e($url).'</link>'."\n"
                .'    <guid isPermaLink="true">'.$e($url).'</guid>'."\n"
                .'    <pubDate>'.$post->published_at?->copy()->utc()->toRfc2822String().'</pubDate>'."\n"
                .'    <description>'.$e($post->excerpt).'</description>'."\n"
                ."  </item>\n";
        }

        $xml .= "</channel>\n</rss>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    private function page(Post $post, bool $isPreview): Response
    {
        $newer = $isPreview ? null : Post::query()->published()
            ->where('published_at', '>', $post->published_at)
            ->orderBy('published_at')
            ->first();
        $older = $isPreview ? null : Post::query()->published()
            ->where('published_at', '<', $post->published_at)
            ->orderByDesc('published_at')
            ->first();

        return Inertia::render('blog/Show', [
            'post' => [
                ...$post->summary(),
                'cover' => ImageStore::url($post->cover_path),
                'html' => $post->bodyHtml(),
                'status' => $post->status->value,
                'is_public' => $post->isPublic(),
            ],
            'preview' => $isPreview,
            'newer' => $newer?->summary(),
            'older' => $older?->summary(),
        ]);
    }
}
