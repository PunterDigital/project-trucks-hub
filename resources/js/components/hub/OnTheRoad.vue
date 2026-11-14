<script setup lang="ts">
import { ArrowRight, Truck } from '@lucide/vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import { formatKm, formatTonnes } from '@/lib/format';
import type { LiveSummary } from '@/types/hub';

withDefaults(
    defineProps<{
        statuses: LiveSummary[];
        showDriver?: boolean;
        empty?: string;
    }>(),
    { showDriver: true, empty: 'Nobody is driving right now.' },
);
</script>

<template>
    <p
        v-if="statuses.length === 0"
        class="py-4 text-center text-sm text-muted-foreground"
    >
        {{ empty }}
    </p>
    <ul v-else class="space-y-3" data-test="on-the-road">
        <li
            v-for="(s, i) in statuses"
            :key="s.driver?.username ?? i"
            class="rounded-lg border p-3"
        >
            <div class="flex items-center justify-between gap-2">
                <DriverLink v-if="showDriver && s.driver" :driver="s.driver" />
                <span
                    class="inline-flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span class="relative flex size-2">
                        <span
                            v-if="!s.parked"
                            class="absolute inline-flex size-full animate-ping rounded-full bg-green-500 opacity-60"
                        />
                        <span
                            :class="[
                                'relative inline-flex size-2 rounded-full',
                                s.parked ? 'bg-amber-500' : 'bg-green-500',
                            ]"
                        />
                    </span>
                    {{ s.parked ? 'Parked' : 'Driving' }}
                </span>
            </div>
            <template v-if="s.on_job">
                <p
                    class="mt-2 flex flex-wrap items-center gap-x-1.5 text-sm font-medium"
                >
                    {{ s.source_city ?? '?' }}
                    <ArrowRight class="size-3.5 text-muted-foreground" />
                    {{ s.dest_city ?? '?' }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ s.cargo_name }}
                    <template v-if="s.cargo_mass_kg">
                        · {{ formatTonnes(s.cargo_mass_kg) }}</template
                    >
                    <template v-if="s.truck"> · {{ s.truck }}</template>
                </p>
                <div v-if="s.progress_pct !== null" class="mt-2">
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-amber-500"
                            :style="{ width: `${s.progress_pct}%` }"
                        />
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground tabular-nums">
                        {{ Math.round(s.progress_pct) }}% ·
                        {{ formatKm(s.distance_left_km) }} to go
                    </p>
                </div>
            </template>
            <p
                v-else
                class="mt-2 flex items-center gap-1.5 text-sm text-muted-foreground"
            >
                <Truck class="size-3.5" /> Free roaming<template v-if="s.truck">
                    in a {{ s.truck }}</template
                >
            </p>
        </li>
    </ul>
</template>
