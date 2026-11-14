<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ProjectTrucks' latest GitHub release, for the /download page. Looked up at most every
 * {@see self::TTL_SECONDS}; the last good answer is kept indefinitely, so a GitHub outage or
 * rate limit (60 unauthenticated calls an hour) never empties the page.
 *
 * @phpstan-type Installer array{kind: string, name: string, size: int, url: string, sha256: string|null}
 * @phpstan-type Release array{version: string, published_at: string|null, notes_url: string, installers: list<Installer>}
 */
final class LatestRelease
{
    public const CACHE_KEY = 'projecttrucks.release.latest';

    public const LAST_GOOD_KEY = 'projecttrucks.release.last-good';

    public const TTL_SECONDS = 900;

    public const RELEASES_PAGE = 'https://github.com/PunterDigital/project-trucks/releases/latest';

    private const API_URL = 'https://api.github.com/repos/PunterDigital/project-trucks/releases/latest';

    /** Asset name → installer kind, most wanted first. */
    private const KINDS = [
        'exe' => '/_x64-setup\.exe$/i',
        'msi' => '/_x64_[a-z-]+\.msi$/i',
    ];

    /** @return Release|null */
    public static function get(): ?array
    {
        /** @var Release|false|null $cached */
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $fresh = self::fetch();
        if ($fresh !== null) {
            Cache::put(self::CACHE_KEY, $fresh, self::TTL_SECONDS);
            Cache::forever(self::LAST_GOOD_KEY, $fresh);

            return $fresh;
        }

        /** @var Release|null $lastGood */
        $lastGood = Cache::get(self::LAST_GOOD_KEY);
        // Don't ask GitHub again on every request while it's failing.
        Cache::put(self::CACHE_KEY, $lastGood ?? false, 60);

        return $lastGood;
    }

    /** @return Installer|null */
    public static function installer(string $kind): ?array
    {
        foreach (self::get()['installers'] ?? [] as $installer) {
            if ($installer['kind'] === $kind) {
                return $installer;
            }
        }

        return null;
    }

    /** @return Release|null */
    private static function fetch(): ?array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'ProjectTrucks-Hub'])
                ->get(self::API_URL);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();
        $tag = (string) ($data['tag_name'] ?? '');
        if ($tag === '' || ($data['draft'] ?? false) || ($data['prerelease'] ?? false)) {
            return null;
        }

        $installers = [];
        foreach (self::KINDS as $kind => $pattern) {
            foreach ((array) ($data['assets'] ?? []) as $asset) {
                if (! is_array($asset) || ! preg_match($pattern, (string) ($asset['name'] ?? ''))) {
                    continue;
                }
                $url = (string) ($asset['browser_download_url'] ?? '');
                // Only ever send people to GitHub's own release downloads.
                if (! str_starts_with($url, 'https://github.com/PunterDigital/project-trucks/releases/download/')) {
                    continue;
                }
                $digest = (string) ($asset['digest'] ?? '');
                $installers[] = [
                    'kind' => $kind,
                    'name' => (string) $asset['name'],
                    'size' => (int) ($asset['size'] ?? 0),
                    'url' => $url,
                    'sha256' => str_starts_with($digest, 'sha256:') ? substr($digest, 7) : null,
                ];
                break;
            }
        }

        if ($installers === []) {
            return null;
        }

        return [
            'version' => ltrim($tag, 'v'),
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : null,
            'notes_url' => (string) ($data['html_url'] ?? self::RELEASES_PAGE),
            'installers' => $installers,
        ];
    }
}
