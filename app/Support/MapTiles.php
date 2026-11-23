<?php

namespace App\Support;

/**
 * The ETS2 map tiles for the live map (#4687). `tools/map-tiles/generate.mjs` renders them from
 * the game files with ts-map into `public/map-tiles/ets2/` (static files, committed; never built
 * on Forge). `TileMapInfo.json` there gives the game-world box (SCS metres) that the single
 * zoom-0 tile covers, which is all the page needs to place pins.
 *
 * Not under `public/map/`: a folder with the same name as the `/map` route would make the web
 * server answer for the folder instead of Laravel.
 */
class MapTiles
{
    public const DIR = 'map-tiles/ets2';

    /**
     * `present` comes from `TileIndex.json` (#5207): per zoom, a base64 bitmask (bit x * 2^z + y)
     * of the tiles that exist. Blank tiles (sea, outside the map) aren't stored, and the page
     * draws the background for them instead of asking for a file. Null for a tile set without
     * an index: every tile is then requested.
     *
     * @return array{url: string, x1: float, x2: float, y1: float, y2: float, maxZoom: int, present: array<int, string>|null}|null
     */
    public static function info(): ?array
    {
        $file = public_path(self::DIR.'/TileMapInfo.json');

        if (! is_file($file)) {
            return null;
        }

        $info = json_decode((string) file_get_contents($file), true);

        if (! is_array($info) || ! isset($info['x1'], $info['x2'], $info['y1'], $info['y2'], $info['maxZoom'])) {
            return null;
        }

        return [
            'url' => '/'.self::DIR.'/tiles/{z}/{x}/{y}.png?v='.self::version($file),
            'x1' => (float) $info['x1'],
            'x2' => (float) $info['x2'],
            'y1' => (float) $info['y1'],
            'y2' => (float) $info['y2'],
            'maxZoom' => (int) $info['maxZoom'],
            'present' => self::present((int) $info['maxZoom']),
        ];
    }

    /**
     * The tiles' `?v=` (#5733): the content hash `generate.mjs` stores in `TileIndex.json`, so it
     * changes only when the tiles do. Never a file time: a zero-downtime release is a fresh
     * checkout, so every deploy would make every visitor (and Cloudflare) fetch every tile anew.
     * A set without the hash falls back to a hash of its two JSON files.
     */
    private static function version(string $infoFile): string
    {
        $indexFile = public_path(self::DIR.'/TileIndex.json');
        $index = is_file($indexFile) ? (string) file_get_contents($indexFile) : '';
        $decoded = json_decode($index, true);
        $hash = is_array($decoded) ? ($decoded['hash'] ?? null) : null;

        if (is_string($hash) && preg_match('/^[0-9a-f]{8,64}$/', $hash) === 1) {
            return $hash;
        }

        return substr(hash('sha256', (string) file_get_contents($infoFile)."\0".$index), 0, 16);
    }

    /** @return array<int, string>|null */
    private static function present(int $maxZoom): ?array
    {
        $file = public_path(self::DIR.'/TileIndex.json');

        if (! is_file($file)) {
            return null;
        }

        $index = json_decode((string) file_get_contents($file), true);
        $tiles = is_array($index) ? ($index['tiles'] ?? null) : null;

        if (! is_array($tiles)) {
            return null;
        }

        $present = [];
        for ($z = 0; $z <= $maxZoom; $z++) {
            $mask = $tiles[(string) $z] ?? null;
            if (! is_string($mask) || base64_decode($mask, true) === false) {
                return null;
            }
            $present[$z] = $mask;
        }

        return $present;
    }
}
