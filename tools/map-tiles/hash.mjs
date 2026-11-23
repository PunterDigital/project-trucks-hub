#!/usr/bin/env node
// The tile set's content hash (#5733): sha256 over every tile's path and bytes, in a fixed order.
// MapTiles puts it in the tile URL as ?v=, so browsers and Cloudflare keep the tiles across
// deploys (a zero-downtime release is a fresh checkout, so file times change on every deploy)
// and fetch them anew only when the tiles themselves change. generate.mjs stores it in
// TileIndex.json as "hash"; run this file on its own to stamp an existing tile set:
//
//   node tools/map-tiles/hash.mjs [public/map-tiles/ets2]

import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** Tile paths relative to `dir`, `tiles/z/x/y.png` with forward slashes, sorted. */
function tileFiles(dir) {
    const out = [];
    const walk = (rel) => {
        for (const entry of readdirSync(path.join(dir, rel), {
            withFileTypes: true,
        })) {
            const child = rel ? `${rel}/${entry.name}` : entry.name;
            if (entry.isDirectory()) walk(child);
            else if (entry.name.endsWith('.png')) out.push(child);
        }
    };
    walk('tiles');
    return out.sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
}

/** First 16 hex characters of the sha256 over each tile's path and bytes. */
export function tilesHash(dir) {
    const hash = createHash('sha256');
    for (const file of tileFiles(dir)) {
        hash.update(file);
        hash.update('\0');
        hash.update(readFileSync(path.join(dir, file)));
    }
    return hash.digest('hex').slice(0, 16);
}

/** Writes the hash into the set's TileIndex.json and returns it. */
export function stampIndex(dir) {
    const file = path.join(dir, 'TileIndex.json');
    const index = JSON.parse(readFileSync(file, 'utf8'));
    index.hash = tilesHash(dir);
    // Indented as `npm run check:fix` formats it, so a stamp leaves no formatting diff.
    writeFileSync(file, `${JSON.stringify(index, null, 4)}\n`);
    return index.hash;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const here = path.dirname(fileURLToPath(import.meta.url));
    const dir = path.resolve(
        process.argv[2] ?? path.join(here, '../../public/map-tiles/ets2'),
    );
    console.log(`TileIndex.json hash: ${stampIndex(dir)}`);
}
