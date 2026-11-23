<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * TruckersMP events (#5228): most VTC convoys are announced on TruckersMP, so a company event
 * can link to one and fill its form from TruckersMP's public API (no key). Called from the
 * server only, with a 5 s timeout; good answers are cached for 10 minutes.
 */
class TruckersMpEvents
{
    public const API = 'https://api.truckersmp.com/v2/events/';

    public const SITE = 'https://truckersmp.com/events/';

    /** Route images come from TruckersMP's image host only (the event page shows them). */
    public const IMAGE_PREFIX = 'https://static.truckersmp.com/images/';

    public const CACHE_MINUTES = 10;

    public const TIMEOUT_SECONDS = 5;

    /** An event page link (`https://truckersmp.com/events/38846-some-convoy`) or a bare id. */
    private const URL_PATTERN = '~^(?:(?:https?://)?(?:www\.)?truckersmp\.com/events/)?(\d{1,9})(?:-[A-Za-z0-9-]*)?/?(?:[?#].*)?$~';

    public static function cacheKey(int $id): string
    {
        return 'truckersmp:event:'.$id;
    }

    /** The event id in what was pasted, or null when it isn't a TruckersMP event link. */
    public static function parseId(?string $input): ?int
    {
        $input = trim((string) $input);
        if ($input === '' || preg_match(self::URL_PATTERN, $input, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    public static function url(int $id): string
    {
        return self::SITE.$id;
    }

    public static function isImageUrl(?string $url): bool
    {
        return is_string($url)
            && str_starts_with($url, self::IMAGE_PREFIX)
            && strlen($url) <= 500
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * The event form's fields for a TruckersMP event.
     *
     * @return array{truckersmp_url: string, title: string, starts_at: string|null, game: string|null, server: string|null, start_city: string|null, start_company: string|null, dest_city: string|null, dest_company: string|null, route_image_url: string|null}
     *
     * @throws TruckersMpException with a message for the driver
     */
    public static function fields(int $id): array
    {
        return self::parse(self::fetch($id));
    }

    /**
     * The API's `response` object, from the cache when fresh.
     *
     * @return array<string, mixed>
     *
     * @throws TruckersMpException
     */
    public static function fetch(int $id): array
    {
        $cached = Cache::get(self::cacheKey($id));
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                // A browser-like user agent gets Cloudflare's challenge page instead of JSON.
                ->withUserAgent('ProjectTrucksHub/1.0 (+'.config('app.url').')')
                ->get(self::API.$id);
        } catch (ConnectionException) {
            throw new TruckersMpException(__('TruckersMP didn’t answer. Try again in a minute, or fill the form in yourself.'));
        }

        $json = $response->json();
        if ($response->status() === 404 || (is_array($json) && ($json['error'] ?? false) === true && $response->successful())) {
            throw new TruckersMpException(__('TruckersMP has no event with that link.'));
        }
        if (! $response->successful() || ! is_array($json) || ! is_array($json['response'] ?? null)) {
            throw new TruckersMpException(__('TruckersMP didn’t answer. Try again in a minute, or fill the form in yourself.'));
        }

        Cache::put(self::cacheKey($id), $json['response'], now()->addMinutes(self::CACHE_MINUTES));

        return $json['response'];
    }

    /**
     * Turns the API's event into the form's fields. Text is cut to the form's limits.
     *
     * @param  array<string, mixed>  $event
     * @return array{truckersmp_url: string, title: string, starts_at: string|null, game: string|null, server: string|null, start_city: string|null, start_company: string|null, dest_city: string|null, dest_company: string|null, route_image_url: string|null}
     *
     * @throws TruckersMpException
     */
    public static function parse(array $event): array
    {
        $id = $event['id'] ?? null;
        $name = self::text($event['name'] ?? null, 120);
        if (! is_int($id) || $id <= 0 || $name === null) {
            throw new TruckersMpException(__('TruckersMP sent an event we couldn’t read. Fill the form in yourself.'));
        }

        $game = match (strtoupper((string) ($event['game'] ?? ''))) {
            'ETS2' => 'ets2',
            'ATS' => 'ats',
            default => null,
        };
        $map = $event['map'] ?? null;

        return [
            'truckersmp_url' => self::url($id),
            'title' => $name,
            'starts_at' => self::utc($event['start_at'] ?? null),
            'game' => $game,
            'server' => self::text(self::get($event, 'server.name'), 80),
            'start_city' => self::text(self::get($event, 'departure.city'), 80),
            'start_company' => self::text(self::get($event, 'departure.location'), 80),
            'dest_city' => self::text(self::get($event, 'arrive.city'), 80),
            'dest_company' => self::text(self::get($event, 'arrive.location'), 80),
            'route_image_url' => self::isImageUrl(is_string($map) ? $map : null) ? $map : null,
        ];
    }

    /** @param array<string, mixed> $event */
    private static function get(array $event, string $path): mixed
    {
        return data_get($event, $path);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, $max - 1, '…');
    }

    /** TruckersMP's times are UTC (`2026-12-06 19:00:00`). */
    private static function utc(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
