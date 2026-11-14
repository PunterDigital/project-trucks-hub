#!/usr/bin/env node
// Renders the ETS2 map tiles for the Hub's live map (#4687) from your local game files and puts
// them in public/map-tiles/ets2 (committed; Forge never builds them). Run it again after a game or
// map DLC update. Windows only (ts-map draws with System.Drawing), needs the .NET 10 SDK and git.
//
//   node tools/map-tiles/generate.mjs [--game "<ETS2 folder>"] [--max-zoom 6] [--shards 6]
//
// Uses ts-map (https://github.com/dariowouters/ts-map, MIT) at a pinned commit: its library
// sources are compiled into TileExporter (this folder), a console program with no window.

import { execFileSync, spawn } from 'node:child_process';
import {
    cpSync,
    existsSync,
    mkdirSync,
    readdirSync,
    rmSync,
    statSync,
} from 'node:fs';
import { cpus, tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const TS_MAP_REPO = 'https://github.com/dariowouters/ts-map.git';
const TS_MAP_COMMIT = 'ae8b4c0e77c7af74ca5e76eef84d06e2864732eb'; // 2026-11-10, ETS2 1.59

const here = path.dirname(fileURLToPath(import.meta.url));
const hub = path.resolve(here, '../..');
const target = path.join(hub, 'public', 'map-tiles', 'ets2');

function arg(name, fallback) {
    const i = process.argv.indexOf(`--${name}`);
    return i > 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const game = arg(
    'game',
    'C:/Program Files (x86)/Steam/steamapps/common/Euro Truck Simulator 2',
);
const maxZoom = Number(arg('max-zoom', '6'));
const shards = Number(
    arg('shards', String(Math.max(1, Math.min(8, cpus().length - 2)))),
);

if (!existsSync(path.join(game, 'base_map.scs'))) {
    console.error(
        `No ETS2 here: ${game}\nPass --game "<your Euro Truck Simulator 2 folder>".`,
    );
    process.exit(1);
}

const work = path.join(tmpdir(), 'projecttrucks-map-tiles');
const src = path.join(work, `ts-map-${TS_MAP_COMMIT.slice(0, 7)}`);
const bin = path.join(work, 'bin');
const out = path.join(work, 'out');

if (!existsSync(src)) {
    console.log(`Fetching ts-map ${TS_MAP_COMMIT.slice(0, 7)}…`);
    execFileSync('git', ['clone', '--quiet', TS_MAP_REPO, src], {
        stdio: 'inherit',
    });
    execFileSync('git', ['-C', src, 'checkout', '--quiet', TS_MAP_COMMIT], {
        stdio: 'inherit',
    });
}

console.log('Building TileExporter…');
execFileSync(
    'dotnet',
    [
        'build',
        path.join(here, 'TileExporter.csproj'),
        '-c',
        'Release',
        '-nologo',
        '-v',
        'quiet',
        `-p:TsMapSrc=${src}`,
        `-p:BaseIntermediateOutputPath=${path.join(work, 'obj')}${path.sep}`,
        '-o',
        bin,
    ],
    { stdio: 'inherit' },
);

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

console.log(
    `Rendering zoom 0–${maxZoom} with ${shards} processes (each parses the map first)…`,
);
const started = Date.now();
await Promise.all(
    Array.from(
        { length: shards },
        (_, i) =>
            new Promise((resolve, reject) => {
                const child = spawn(
                    path.join(bin, 'TileExporter.exe'),
                    [game, out, String(maxZoom), String(i), String(shards)],
                    {
                        stdio: ['ignore', 'inherit', 'inherit'],
                        windowsHide: true,
                    },
                );
                child.on('exit', (code) =>
                    code === 0
                        ? resolve()
                        : reject(new Error(`shard ${i} exited with ${code}`)),
                );
            }),
    ),
);

let files = 0;
let bytes = 0;
const walk = (dir) => {
    for (const name of readdirSync(dir)) {
        const p = path.join(dir, name);
        const s = statSync(p);
        if (s.isDirectory()) walk(p);
        else {
            files++;
            bytes += s.size;
        }
    }
};
walk(out);

rmSync(target, { recursive: true, force: true });
mkdirSync(path.dirname(target), { recursive: true });
cpSync(out, target, { recursive: true });

console.log(
    `Done in ${Math.round((Date.now() - started) / 1000)} s: ${files} files, ${(bytes / 1048576).toFixed(1)} MB in ${path.relative(hub, target)}`,
);
console.log('Check /map locally, then commit public/map-tiles/ets2.');
