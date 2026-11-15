<script setup lang="ts">
import { computed } from 'vue';
import { formatKm, plural } from '@/lib/format';
import type { CargoRow } from '@/types/hub';

/** Counted jobs by cargo, most first (#4650). */
const props = defineProps<{ cargo: CargoRow[] }>();

const max = computed(() =>
    Math.max(1, ...props.cargo.map((c) => c.deliveries)),
);
</script>

<template>
    <p
        v-if="cargo.length === 0"
        class="py-4 text-center text-sm text-muted-foreground"
    >
        No cargo yet.
    </p>
    <ul v-else class="space-y-2.5" data-test="cargo-chart">
        <li
            v-for="c in cargo"
            :key="c.name"
            class="space-y-1 text-sm"
            :title="`${c.name}: ${plural(c.deliveries, 'job')}, ${formatKm(c.distance_km)}`"
        >
            <div class="flex justify-between gap-2">
                <span class="truncate font-medium">{{ c.name }}</span>
                <span class="text-muted-foreground tabular-nums">{{
                    plural(c.deliveries, 'job')
                }}</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full"
                    :style="{
                        width: `${(c.deliveries / max) * 100}%`,
                        backgroundColor: 'var(--chart-2)',
                    }"
                />
            </div>
        </li>
    </ul>
</template>
