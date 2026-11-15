<script setup lang="ts">
import { computed } from 'vue';
import { formatKm } from '@/lib/format';
import type { ChartSeries } from '@/types/hub';

/**
 * Distance per day over the last `days` days in the viewer's own time zone (#4650), as
 * plain divs so it follows the theme. The server sends 15-minute UTC buckets; each one
 * lands on exactly one local day, so no offset has to be sent anywhere.
 */
const props = withDefaults(
    defineProps<{
        series: ChartSeries[];
        days?: number;
    }>(),
    { days: 30 },
);

const COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-4)',
    'var(--chart-3)',
    'var(--chart-5)',
];

function color(index: number, key: string): string {
    return key === 'others'
        ? 'var(--muted-foreground)'
        : COLORS[index % COLORS.length];
}

function dayKey(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const dayLabel = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const shortLabel = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

const columns = computed(() => {
    const now = new Date();
    const list = Array.from({ length: props.days }, (_, i) => {
        // Local midnight, n days back; `new Date(y, m, d)` handles DST and month ends.
        const date = new Date(
            now.getFullYear(),
            now.getMonth(),
            now.getDate() - (props.days - 1 - i),
        );

        return {
            key: dayKey(date),
            date,
            values: props.series.map(() => 0),
        };
    });
    const byKey = new Map(list.map((c) => [c.key, c]));

    props.series.forEach((s, si) => {
        for (const [ts, km] of s.points) {
            const column = byKey.get(dayKey(new Date(ts * 1000)));

            if (column) {
                column.values[si] += km;
            }
        }
    });

    return list.map((c) => {
        const total = c.values.reduce((a, b) => a + b, 0);
        const parts = props.series
            .map((s, si) => ({ s, km: c.values[si] }))
            .filter((p) => p.km > 0)
            .map((p) =>
                props.series.length > 1
                    ? `${p.s.name}: ${formatKm(p.km)}`
                    : formatKm(p.km),
            );

        return {
            ...c,
            total: Math.round(total * 10) / 10,
            title: `${dayLabel.format(c.date)}: ${
                total > 0 ? parts.join(', ') : '0 km'
            }`,
        };
    });
});

const max = computed(() => Math.max(0, ...columns.value.map((c) => c.total)));
const total = computed(() =>
    columns.value.reduce((sum, c) => sum + c.total, 0),
);

function height(km: number): string {
    return max.value > 0 ? `${(km / max.value) * 100}%` : '0%';
}

const summary = computed(
    () =>
        `Distance per day for the last ${props.days} days, ${formatKm(total.value)} in total, at most ${formatKm(max.value)} in a day`,
);
</script>

<template>
    <div class="space-y-2" data-test="distance-chart">
        <div
            class="flex items-baseline justify-between text-xs text-muted-foreground"
        >
            <span class="tabular-nums" data-test="chart-total"
                >{{ formatKm(total) }} in {{ days }} days</span
            >
            <span v-if="max > 0" class="tabular-nums"
                >Best day {{ formatKm(max) }}</span
            >
        </div>
        <div
            role="img"
            :aria-label="summary"
            class="flex h-36 items-end gap-px border-b border-border sm:gap-0.5"
        >
            <div
                v-for="c in columns"
                :key="c.key"
                :title="c.title"
                data-test="chart-day"
                :data-date="c.key"
                :data-km="c.total"
                class="flex h-full flex-1 flex-col-reverse transition-opacity hover:opacity-80"
            >
                <div
                    v-for="(s, si) in series"
                    v-show="c.values[si] > 0"
                    :key="s.key"
                    class="w-full"
                    :style="{
                        height: height(c.values[si]),
                        backgroundColor: color(si, s.key),
                    }"
                />
            </div>
        </div>
        <div
            class="flex justify-between text-xs text-muted-foreground tabular-nums"
        >
            <span>{{ shortLabel.format(columns[0].date) }}</span>
            <span>Today</span>
        </div>
        <p v-if="total === 0" class="text-center text-sm text-muted-foreground">
            No distance in the last {{ days }} days.
        </p>
        <ul
            v-if="series.length > 1"
            class="flex flex-wrap gap-x-4 gap-y-1 pt-1 text-xs"
            data-test="chart-legend"
        >
            <li
                v-for="(s, si) in series"
                :key="s.key"
                class="flex items-center gap-1.5"
            >
                <span
                    class="size-2.5 rounded-sm"
                    :style="{ backgroundColor: color(si, s.key) }"
                />
                <span class="font-medium">{{ s.name }}</span>
                <span class="text-muted-foreground tabular-nums">{{
                    formatKm(s.distance_km)
                }}</span>
            </li>
        </ul>
    </div>
</template>
