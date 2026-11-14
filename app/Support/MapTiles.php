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

    /** @return array{url: string, x1: float, x2: float, y1: float, y2: float, maxZoom: int}|null */
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
            'url' => '/'.self::DIR.'/tiles/{z}/{x}/{y}.png?v='.filemtime($file),
            'x1' => (float) $info['x1'],
            'x2' => (float) $info['x2'],
            'y1' => (float) $info['y1'],
            'y2' => (float) $info['y2'],
            'maxZoom' => (int) $info['maxZoom'],
        ];
    }
}
