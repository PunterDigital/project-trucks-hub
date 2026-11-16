<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Trophy } from '@lucide/vue';
import CompanyLogo from '@/components/hub/CompanyLogo.vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import { formatCount, formatKm, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { leaderboards } from '@/routes';
import { show as showCompany } from '@/routes/companies';
import type {
    CompanySummary,
    DriverSummary,
    LeaderboardRow,
} from '@/types/hub';

type Board = 'drivers' | 'companies';
type PeriodKey = 'week' | 'month' | 'all';
type Metric = 'distance' | 'deliveries' | 'income';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Leaderboards', href: leaderboards() }] },
});

const props = defineProps<{
    board: Board;
    period: PeriodKey;
    metric: Metric;
    rows: LeaderboardRow[];
}>();

const boards: { key: Board; label: string }[] = [
    { key: 'drivers', label: 'Drivers' },
    { key: 'companies', label: 'Companies' },
];
const periods: { key: PeriodKey; label: string }[] = [
    { key: 'week', label: 'This week' },
    { key: 'month', label: 'This month' },
    { key: 'all', label: 'All time' },
];
const metrics: { key: Metric; label: string }[] = [
    { key: 'distance', label: 'Distance' },
    { key: 'deliveries', label: 'Deliveries' },
    { key: 'income', label: 'Income' },
];

function href(
    change: Partial<{ board: Board; period: PeriodKey; metric: Metric }>,
) {
    return leaderboards({
        query: {
            board: props.board,
            period: props.period,
            metric: props.metric,
            ...change,
        },
    });
}

const tab = (active: boolean) =>
    cn(
        'rounded-md px-3 py-1 text-sm transition-colors',
        active
            ? 'bg-background font-medium shadow-sm'
            : 'text-muted-foreground hover:text-foreground',
    );

function asCompany(row: LeaderboardRow) {
    return row.subject as CompanySummary & { members: number };
}
</script>

<template>
    <Head title="Leaderboards" />

    <div class="flex flex-col gap-4 p-4">
        <h1 class="flex items-center gap-2 text-xl font-semibold">
            <Trophy class="size-5 text-amber-500" /> Leaderboards
        </h1>

        <div class="flex flex-wrap gap-2">
            <nav class="inline-flex rounded-lg bg-muted p-1" aria-label="Board">
                <Link
                    v-for="b in boards"
                    :key="b.key"
                    :href="href({ board: b.key })"
                    :class="tab(board === b.key)"
                    preserve-scroll
                    >{{ b.label }}</Link
                >
            </nav>
            <nav
                class="inline-flex rounded-lg bg-muted p-1"
                aria-label="Period"
            >
                <Link
                    v-for="p in periods"
                    :key="p.key"
                    :href="href({ period: p.key })"
                    :class="tab(period === p.key)"
                    preserve-scroll
                    >{{ p.label }}</Link
                >
            </nav>
            <nav
                class="inline-flex rounded-lg bg-muted p-1"
                aria-label="Ranked by"
            >
                <Link
                    v-for="m in metrics"
                    :key="m.key"
                    :href="href({ metric: m.key })"
                    :class="tab(metric === m.key)"
                    preserve-scroll
                    >{{ m.label }}</Link
                >
            </nav>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="w-12 px-4 py-2 text-right">#</th>
                        <th class="px-4 py-2">
                            {{ board === 'drivers' ? 'Driver' : 'Company' }}
                        </th>
                        <th class="px-4 py-2 text-right">Distance</th>
                        <th class="px-4 py-2 text-right">Deliveries</th>
                        <th class="px-4 py-2 text-right">Income</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            No deliveries in this period yet.
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.rank"
                        class="border-b last:border-0"
                        data-test="leaderboard-row"
                    >
                        <td
                            :class="[
                                'px-4 py-2 text-right font-semibold tabular-nums',
                                row.rank <= 3
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-muted-foreground',
                            ]"
                        >
                            {{ row.rank }}
                        </td>
                        <td class="px-4 py-2">
                            <DriverLink
                                v-if="board === 'drivers'"
                                :driver="row.subject as DriverSummary"
                            />
                            <div v-else class="flex items-center gap-2">
                                <CompanyLogo
                                    :company="asCompany(row)"
                                    class="size-8"
                                />
                                <div class="min-w-0">
                                    <Link
                                        :href="showCompany(asCompany(row).slug)"
                                        class="font-medium hover:underline"
                                        >[{{ asCompany(row).tag }}]
                                        {{ asCompany(row).name }}</Link
                                    >
                                    <p class="text-xs text-muted-foreground">
                                        {{ asCompany(row).members }} members
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td
                            :class="[
                                'px-4 py-2 text-right tabular-nums',
                                { 'font-semibold': metric === 'distance' },
                            ]"
                        >
                            {{ formatKm(row.distance_km) }}
                        </td>
                        <td
                            :class="[
                                'px-4 py-2 text-right tabular-nums',
                                { 'font-semibold': metric === 'deliveries' },
                            ]"
                        >
                            {{ formatCount(row.deliveries) }}
                        </td>
                        <td
                            :class="[
                                'px-4 py-2 text-right tabular-nums',
                                { 'font-semibold': metric === 'income' },
                            ]"
                        >
                            {{ formatMoney(row.income) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">
            Counts delivered jobs only (on time or late). Cancelled jobs, jobs
            flagged as implausible (by ProjectTrucks or the Hub's own checks),
            hidden jobs and drivers with a private profile are left out. Weeks
            start on Monday (UTC).
        </p>
    </div>
</template>
