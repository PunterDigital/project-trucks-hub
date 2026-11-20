<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Help guides at /guides (#5151): pages that answer one common Euro Truck Simulator 2
 * question each. They live in the repo (`resources/guides/<slug>.php`), not in the blog's
 * `posts` table, because they describe what ProjectTrucks and the Hub do: a change to a
 * feature should change its guide in the same pull request.
 *
 * Each file returns an array with `title` (also the page's `<Head title>`), `description`
 * (the meta description, at most 160 characters), `answer` (the short answer at the top,
 * plain text), `body` (Markdown), `faq` (a list of `[question, answer]` pairs, plain text,
 * also sent as FAQPage JSON-LD), `updated` (Y-m-d) and `order` (position on /guides).
 *
 * @phpstan-type Guide array{slug: string, title: string, description: string, answer: string, body: string, faq: list<array{0: string, 1: string}>, updated: string, order: int}
 */
class Guides
{
    /** Guide slugs: lowercase words joined by hyphens (also the route constraint). */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** @var list<Guide>|null */
    private static ?array $cache = null;

    /** @return list<Guide> every guide, in `order` */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $guides = [];
        foreach (glob(resource_path('guides/*.php')) ?: [] as $file) {
            $slug = basename($file, '.php');
            $data = require $file;
            if (! is_array($data) || preg_match('/^'.self::SLUG_PATTERN.'$/', $slug) !== 1) {
                throw new RuntimeException("Guide file {$file} is not a guide.");
            }

            /** @var Guide $guide */
            $guide = ['slug' => $slug, ...$data];
            $guides[] = $guide;
        }

        usort($guides, fn (array $a, array $b) => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]);

        return self::$cache = $guides;
    }

    /** @return Guide|null */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $guide) {
            if ($guide['slug'] === $slug) {
                return $guide;
            }
        }

        return null;
    }

    /**
     * What /guides lists.
     *
     * @param  Guide  $guide
     * @return array{slug: string, title: string, description: string}
     */
    public static function summary(array $guide): array
    {
        return ['slug' => $guide['slug'], 'title' => $guide['title'], 'description' => $guide['description']];
    }

    /**
     * What a guide's page gets: the body rendered with the blog's Markdown rules (raw HTML
     * stripped, unsafe links dropped), and the date already formatted, so the server and the
     * browser print the same text.
     *
     * @param  Guide  $guide
     * @return array{slug: string, title: string, description: string, answer: string, html: string, faq: list<array{question: string, answer: string}>, updated: string, updated_label: string}
     */
    public static function page(array $guide): array
    {
        return [
            ...self::summary($guide),
            'answer' => $guide['answer'],
            'html' => Str::markdown($guide['body'], [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 20,
            ]),
            'faq' => array_map(fn (array $qa) => ['question' => $qa[0], 'answer' => $qa[1]], $guide['faq']),
            'updated' => $guide['updated'],
            'updated_label' => Carbon::parse($guide['updated'])->format('j F Y'),
        ];
    }

    /** Forget the loaded files (tests). */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
