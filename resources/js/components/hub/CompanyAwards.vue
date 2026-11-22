<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Trophy } from '@lucide/vue';
import Panel from '@/components/hub/Panel.vue';
import { formatKm } from '@/lib/format';
import { show as showDriver } from '@/routes/drivers';

// Driver of the month (#5226): each month's top three by distance driven for the company.
export type AwardWinner = {
    place: number;
    distance_km: number;
    driver: { name: string; username: string | null; avatar: string | null };
};
export type MonthAwards = {
    month: string;
    label: string;
    winners: AwardWinner[];
};

defineProps<{ awards: MonthAwards[] }>();

const places = ['1st', '2nd', '3rd'];
</script>

<template>
    <Panel
        title="Driver of the month"
        description="Top three by distance each month"
        data-test="company-awards"
    >
        <ul class="space-y-3">
            <li v-for="m in awards" :key="m.month" data-test="award-month">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ m.label }}
                </p>
                <ol class="mt-1 space-y-1">
                    <li
                        v-for="w in m.winners"
                        :key="w.place"
                        class="flex items-center gap-2 text-sm"
                        data-test="award-winner"
                    >
                        <Trophy
                            class="size-3.5 shrink-0"
                            :class="
                                w.place === 1
                                    ? 'text-amber-500'
                                    : 'text-muted-foreground'
                            "
                            aria-hidden="true"
                        />
                        <span class="w-7 text-xs font-semibold tabular-nums">{{
                            places[w.place - 1]
                        }}</span>
                        <Link
                            v-if="w.driver.username"
                            :href="showDriver(w.driver.username)"
                            class="min-w-0 flex-1 truncate hover:underline"
                            >{{ w.driver.name }}</Link
                        >
                        <span
                            v-else
                            class="min-w-0 flex-1 truncate text-muted-foreground"
                            >{{ w.driver.name }}</span
                        >
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                            >{{ formatKm(w.distance_km) }}</span
                        >
                    </li>
                </ol>
            </li>
        </ul>
    </Panel>
</template>
