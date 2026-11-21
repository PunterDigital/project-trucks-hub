<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { MapPin } from '@lucide/vue';
import type * as Leaflet from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import { map as mapRoute } from '@/routes';
import { show as showDriver } from '@/routes/drivers';
import type { DriverSummary } from '@/types/hub';

/** TileMapInfo.json from ts-map: the game-world box (SCS metres) of the zoom-0 tile. */
type Tiles = {
    url: string;
    x1: number;
    x2: number;
    y1: number;
    y2: number;
    maxZoom: number;
    /** TileIndex.json (#5207): per zoom, a base64 bitmask (bit x·2^z + y) of the tiles that exist. */
    present: string[] | null;
};

type LiveDriver = {
    id: number;
    x: number;
    z: number;
    /** SCS heading: 0 = north, counter-clockwise, degrees. */
    heading: number | null;
    truck: string | null;
    job: { cargo: string; from: string | null; to: string | null } | null;
    parked: boolean;
    driver: DriverSummary;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Live map', href: mapRoute() }] },
});

const props = defineProps<{
    tiles: Tiles | null;
    drivers: LiveDriver[];
    refreshSeconds: number;
}>();

usePoll(props.refreshSeconds * 1000, { only: ['drivers'] });

const el = ref<HTMLElement | null>(null);
let L: typeof Leaflet | null = null;
let map: Leaflet.Map | null = null;
const markers = new Map<number, Leaflet.Marker>();

const TILE = 256;
// Without rendered tiles the pins still need a scale: roughly ETS2's world.
const FALLBACK = { x1: -120000, x2: 120000, y1: -120000, y2: 120000 };

/**
 * Whether a tile file exists, from TileIndex.json's bitmasks. Without an index every tile is
 * requested (an older tile set).
 */
function tileIndex(
    present: string[] | null,
): (z: number, x: number, y: number) => boolean {
    if (!present) {
        return () => true;
    }
    const masks = present.map((mask) =>
        Uint8Array.from(atob(mask), (c) => c.charCodeAt(0)),
    );

    return (z, x, y) => {
        const mask = masks[z];
        const size = 2 ** z;
        if (!mask || x < 0 || y < 0 || x >= size || y >= size) {
            return false;
        }
        const bit = x * size + y;

        return (mask[bit >> 3] & (1 << (bit & 7))) !== 0;
    };
}

/** Game x (east) / z (south) as a Leaflet LatLng: lat = z, lng = x. */
const at = (d: { x: number; z: number }): Leaflet.LatLngExpression => [
    d.z,
    d.x,
];

function pinHtml(d: LiveDriver): string {
    // CSS rotates clockwise; SCS headings turn counter-clockwise from north.
    const turn = -(d.heading ?? 0);
    const colour = d.job ? '#f59e0b' : '#38bdf8';

    return `<div class="hub-pin__arrow" style="transform: rotate(${turn}deg)">
        <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
            <path d="M12 2 L20 21 L12 16.5 L4 21 Z" fill="${colour}" stroke="#0b0d12" stroke-width="1.5" stroke-linejoin="round" />
        </svg>
    </div>`;
}

/** Popup built from text nodes: names and cities come from drivers, never as HTML. */
function popup(d: LiveDriver): HTMLElement {
    const root = document.createElement('div');
    root.className = 'hub-popup';
    root.dataset.test = 'map-popup';

    const name = document.createElement(d.driver.username ? 'a' : 'span');
    name.className = 'hub-popup__name';
    name.textContent = d.driver.name;
    if (d.driver.username && name instanceof HTMLAnchorElement) {
        name.href = showDriver(d.driver.username).url;
    }
    root.append(name);

    if (d.driver.company) {
        const company = document.createElement('div');
        company.className = 'hub-popup__muted';
        company.textContent = `[${d.driver.company.tag}] ${d.driver.company.name}`;
        root.append(company);
    }

    const truck = document.createElement('div');
    truck.dataset.test = 'map-popup-truck';
    truck.textContent = d.truck ?? 'Unknown truck';
    root.append(truck);

    const job = document.createElement('div');
    job.className = 'hub-popup__muted';
    job.dataset.test = 'map-popup-job';
    job.textContent = d.job
        ? `${d.job.from ?? '?'} → ${d.job.to ?? '?'} · ${d.job.cargo}`
        : 'Free-roaming';
    root.append(job);

    if (d.parked) {
        const parked = document.createElement('div');
        parked.className = 'hub-popup__muted';
        parked.textContent = 'Parked';
        root.append(parked);
    }

    return root;
}

function syncMarkers(drivers: LiveDriver[]): void {
    if (!L || !map) {
        return;
    }

    const seen = new Set<number>();

    for (const d of drivers) {
        seen.add(d.id);
        const icon = L.divIcon({
            className: 'hub-pin',
            html: pinHtml(d),
            iconSize: [26, 26],
            iconAnchor: [13, 13],
            popupAnchor: [0, -12],
        });
        const existing = markers.get(d.id);

        if (existing) {
            existing.setLatLng(at(d)).setIcon(icon);
            existing.setPopupContent(popup(d));
        } else {
            const marker = L.marker(at(d), {
                icon,
                title: d.driver.name,
                alt: d.driver.name,
                keyboard: true,
            })
                .bindPopup(popup(d))
                .addTo(map);
            markers.set(d.id, marker);
        }

        markers
            .get(d.id)
            ?.getElement()
            ?.setAttribute('data-driver', String(d.id));
    }

    for (const [id, marker] of markers) {
        if (!seen.has(id)) {
            marker.remove();
            markers.delete(id);
        }
    }
}

onMounted(async () => {
    L = (await import('leaflet')).default;

    if (!el.value) {
        return;
    }

    const box = props.tiles ?? FALLBACK;
    const a = TILE / (box.x2 - box.x1);
    const c = TILE / (box.y2 - box.y1);
    // CRS.Simple maps lng → x and lat → y; scale the game box onto the 256 px zoom-0 tile.
    const crs = L.extend({}, L.CRS.Simple, {
        transformation: new L.Transformation(a, -a * box.x1, c, -c * box.y1),
    });
    const bounds = L.latLngBounds([box.y1, box.x1], [box.y2, box.x2]);
    // Zoom 8 is as close as the map goes; tiles are rendered that deep (#5207), so no level is a
    // stretched copy of the one above. An older, shallower tile set is stretched up to it.
    const maxZoom = Math.max(8, props.tiles?.maxZoom ?? 0);

    map = L.map(el.value, {
        crs,
        minZoom: 0,
        maxZoom,
        zoomSnap: 0.5,
        maxBounds: bounds.pad(0.1),
        attributionControl: true,
    });
    map.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>',
    );

    if (props.tiles) {
        const exists = tileIndex(props.tiles.present);
        // Blank tiles (sea, around the map) aren't stored: draw the map's own background for
        // them instead of a request that would 404 (#5207).
        const BlankAwareTiles = L.TileLayer.extend({
            createTile(
                this: Leaflet.TileLayer,
                coords: Leaflet.Coords,
                done: Leaflet.DoneCallback,
            ): HTMLElement {
                if (exists(coords.z, coords.x, coords.y)) {
                    return L!.TileLayer.prototype.createTile.call(
                        this,
                        coords,
                        done,
                    );
                }
                const blank = document.createElement('div');
                L!.Util.requestAnimFrame(() => done(undefined, blank));

                return blank;
            },
        }) as new (
            url: string,
            options?: Leaflet.TileLayerOptions,
        ) => Leaflet.TileLayer;

        new BlankAwareTiles(props.tiles.url, {
            tileSize: TILE,
            minZoom: 0,
            maxNativeZoom: props.tiles.maxZoom,
            maxZoom,
            noWrap: true,
            // A hair inside the grid: Leaflet also asks for tiles that only touch the edge (404s).
            bounds: bounds.pad(-0.0005),
            attribution:
                'Map rendered with <a href="https://github.com/dariowouters/ts-map" target="_blank" rel="noopener">ts-map</a> from Euro Truck Simulator 2 © <a href="https://scssoft.com" target="_blank" rel="noopener">SCS Software</a>',
        }).addTo(map);
    }

    map.fitBounds(bounds);
    syncMarkers(props.drivers);
});

watch(
    () => props.drivers,
    (drivers) => syncMarkers(drivers),
);

onBeforeUnmount(() => {
    map?.remove();
    map = null;
    markers.clear();
});

function focus(d: LiveDriver): void {
    if (!map) {
        return;
    }

    map.setView(at(d), Math.max(map.getZoom(), 5));
    markers.get(d.id)?.openPopup();
}
</script>

<template>
    <Head title="Live ETS2 map" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-xl font-semibold">Live map</h1>
            <p class="text-sm text-muted-foreground" data-test="map-count">
                {{
                    drivers.length === 1
                        ? '1 driver on the road'
                        : `${drivers.length} drivers on the road`
                }}
                · updates every {{ refreshSeconds }} s
            </p>
        </div>

        <div
            ref="el"
            class="hub-map h-[65vh] min-h-[420px] w-full overflow-hidden rounded-xl border"
            data-test="live-map"
            :data-tiles="tiles ? 'yes' : 'no'"
        />

        <section>
            <h2 class="mb-2 text-sm font-medium">On the road</h2>
            <p
                v-if="drivers.length === 0"
                class="text-sm text-muted-foreground"
                data-test="map-empty"
            >
                Nobody is driving right now. Drivers show up here while
                ProjectTrucks is tracking and the Hub is linked.
            </p>
            <ul v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="d in drivers"
                    :key="d.id"
                    class="flex items-center justify-between gap-2 rounded-lg border p-2"
                    data-test="map-driver"
                >
                    <div class="min-w-0">
                        <DriverLink :driver="d.driver" />
                        <p class="truncate text-xs text-muted-foreground">
                            {{ d.truck ?? 'Unknown truck' }} ·
                            {{
                                d.job
                                    ? `${d.job.from ?? '?'} → ${d.job.to ?? '?'}`
                                    : 'Free-roaming'
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                        :aria-label="`Show ${d.driver.name} on the map`"
                        @click="focus(d)"
                    >
                        <MapPin class="size-4" />
                    </button>
                </li>
            </ul>
            <p class="mt-3 text-xs text-muted-foreground">
                Private profiles and drivers who switched off "Show me on the
                live map" never appear.
                <template v-if="tiles"
                    >Map rendered with ts-map from Euro Truck Simulator 2 game
                    data, © SCS Software.</template
                >
            </p>
        </section>
    </div>
</template>

<style>
.hub-map {
    background: #181b22;
}

.hub-pin {
    background: transparent;
    border: 0;
}

.hub-pin__arrow {
    width: 26px;
    height: 26px;
    filter: drop-shadow(0 1px 2px rgb(0 0 0 / 0.6));
}

.hub-popup {
    display: grid;
    gap: 2px;
    font-size: 13px;
}

.hub-popup__name {
    font-weight: 600;
}

.hub-popup__muted {
    color: var(--muted-foreground);
}

/* Leaflet's own controls and popups are white whatever the theme (#4658). */
.dark .hub-map .leaflet-bar a {
    background: var(--card);
    color: var(--foreground);
    border-bottom-color: var(--border);
}

.dark .hub-map .leaflet-bar a:hover {
    background: var(--accent);
}

.dark .hub-map .leaflet-bar a.leaflet-disabled {
    background: var(--card);
    color: var(--muted-foreground);
}

.dark .hub-map .leaflet-popup-content-wrapper,
.dark .hub-map .leaflet-popup-tip {
    background: var(--popover);
    color: var(--popover-foreground);
}

.dark .hub-map a.leaflet-popup-close-button {
    color: var(--muted-foreground);
}

.dark .hub-map .leaflet-control-attribution {
    background: rgb(0 0 0 / 0.6);
    color: var(--muted-foreground);
}

.dark .hub-map .leaflet-control-attribution a {
    color: var(--foreground);
}
</style>
