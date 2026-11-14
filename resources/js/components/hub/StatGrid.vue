<script setup lang="ts">
import { ref } from 'vue';
import { formatCount, formatKm, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PeriodTotals } from '@/types/hub';

defineProps<{
    stats: PeriodTotals;
}>();

const periods = [
    { key: 'week', label: 'This week' },
    { key: 'month', label: 'This month' },
    { key: 'all', label: 'All time' },
] as const;

const period = ref<'week' | 'month' | 'all'>('month');
</script>

<template>
    <div class="space-y-3">
        <div
            class="inline-flex rounded-lg bg-muted p-1 text-sm"
            role="group"
            aria-label="Period"
        >
            <button
                v-for="p in periods"
                :key="p.key"
                type="button"
                :class="
                    cn(
                        'rounded-md px-3 py-1 transition-colors',
                        period === p.key
                            ? 'bg-background font-medium shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )
                "
                :aria-pressed="period === p.key"
                @click="period = p.key"
            >
                {{ p.label }}
            </button>
        </div>
        <dl class="grid grid-cols-3 gap-3">
            <div
                class="rounded-xl border p-4"
                data-test="stat"
                data-label="distance"
            >
                <dt class="text-xs text-muted-foreground">Distance</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatKm(stats[period].distance_km) }}
                </dd>
            </div>
            <div
                class="rounded-xl border p-4"
                data-test="stat"
                data-label="deliveries"
            >
                <dt class="text-xs text-muted-foreground">Deliveries</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatCount(stats[period].deliveries) }}
                </dd>
            </div>
            <div
                class="rounded-xl border p-4"
                data-test="stat"
                data-label="income"
            >
                <dt class="text-xs text-muted-foreground">Income</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">
                    {{ formatMoney(stats[period].income) }}
                </dd>
            </div>
        </dl>
    </div>
</template>
