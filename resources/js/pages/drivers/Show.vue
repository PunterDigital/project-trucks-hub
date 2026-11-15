<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ExternalLink, Lock, Trophy } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CargoChart from '@/components/hub/CargoChart.vue';
import CompanyLogo from '@/components/hub/CompanyLogo.vue';
import DistanceChart from '@/components/hub/DistanceChart.vue';
import DriverAvatar from '@/components/hub/DriverAvatar.vue';
import JobList from '@/components/hub/JobList.vue';
import OnTheRoad from '@/components/hub/OnTheRoad.vue';
import Pagination from '@/components/hub/Pagination.vue';
import Panel from '@/components/hub/Panel.vue';
import ReportButton from '@/components/hub/ReportButton.vue';
import StatGrid from '@/components/hub/StatGrid.vue';
import { Badge } from '@/components/ui/badge';
import { formatDate, formatKm, plural } from '@/lib/format';
import { show as showCompany } from '@/routes/companies';
import type {
    CargoRow,
    ChartPoint,
    ChartSeries,
    CompanyRole,
    DriverProgress,
    DriverSummary,
    JobSummary,
    LiveSummary,
    Paginated,
    PeriodTotals,
} from '@/types/hub';

const props = defineProps<{
    driver: DriverSummary & {
        role: CompanyRole | null;
        member_since: string | null;
        steam_url: string | null;
        private: boolean;
    };
    isOwner: boolean;
    stats: PeriodTotals;
    onTime: number | null;
    trucks: { name: string; deliveries: number; distance_km: number }[];
    charts: { distance: ChartPoint[]; cargo: CargoRow[] };
    live: LiveSummary | null;
    jobs: Paginated<JobSummary>;
    progress: DriverProgress;
}>();

const signedIn = usePage().props.auth.user !== null;

// Jobs | Achievements (#4645); a link to #achievements opens that tab.
const tab = ref<'jobs' | 'achievements'>('jobs');
onMounted(() => {
    if (window.location.hash === '#achievements') {
        tab.value = 'achievements';
    }
});
const distanceSeries = computed<ChartSeries[]>(() => [
    {
        key: 'driver',
        name: props.driver.name,
        username: props.driver.username,
        distance_km: 0,
        points: props.charts.distance,
    },
]);
const unlockedCount = computed(
    () => props.progress.achievements.filter((a) => a.unlocked_at).length,
);
</script>

<template>
    <Head :title="driver.name" />

    <div class="flex flex-col gap-4 p-4">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <DriverAvatar
                :name="driver.name"
                :avatar="driver.avatar"
                class="size-16"
            />
            <div class="flex-1 space-y-1">
                <h1
                    class="flex flex-wrap items-center gap-2 text-2xl font-semibold"
                >
                    {{ driver.name }}
                    <Badge v-if="driver.private" variant="outline" class="gap-1"
                        ><Lock class="size-3" /> Private</Badge
                    >
                </h1>
                <p class="text-sm text-muted-foreground">
                    @{{ driver.username }} · driving since
                    {{ formatDate(driver.member_since) }}
                    <template v-if="onTime !== null">
                        · {{ onTime }}% on time</template
                    >
                </p>
                <div
                    class="flex max-w-sm items-center gap-3 pt-1"
                    data-test="driver-level"
                >
                    <span class="text-sm font-semibold whitespace-nowrap"
                        >Level {{ progress.level }} · {{ progress.rank }}</span
                    >
                    <div
                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="progress.level_progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="`${formatKm(progress.distance_km)} of ${formatKm(progress.next_level_km)} to level ${progress.level + 1}`"
                    >
                        <div
                            class="h-full rounded-full bg-amber-500"
                            :style="{ width: `${progress.level_progress}%` }"
                        />
                    </div>
                    <span
                        class="text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                        >{{ formatKm(progress.next_level_km) }}</span
                    >
                </div>
                <p
                    v-if="driver.company"
                    class="flex flex-wrap items-center gap-1.5 text-sm"
                >
                    <span class="capitalize">{{ driver.role }}</span> at
                    <CompanyLogo
                        v-if="driver.company.logo"
                        :company="driver.company"
                        class="size-5 rounded-sm"
                    />
                    <Link
                        :href="showCompany(driver.company.slug)"
                        class="font-medium hover:underline"
                        >[{{ driver.company.tag }}]
                        {{ driver.company.name }}</Link
                    >
                </p>
            </div>
            <a
                v-if="driver.steam_url"
                :href="driver.steam_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"
                >Steam profile <ExternalLink class="size-3.5"
            /></a>
            <ReportButton
                v-if="signedIn && !isOwner"
                type="driver"
                :id="driver.id!"
                what="this driver"
            />
        </header>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <Panel title="Stats">
                    <StatGrid :stats="stats" />
                </Panel>
                <Panel title="Last 30 days" description="Distance per day">
                    <DistanceChart :series="distanceSeries" />
                </Panel>
                <div
                    id="achievements"
                    role="tablist"
                    class="flex gap-1 self-start rounded-lg bg-muted p-1"
                >
                    <button
                        v-for="t in ['jobs', 'achievements'] as const"
                        :key="t"
                        type="button"
                        role="tab"
                        :aria-selected="tab === t"
                        :class="[
                            'rounded-md px-3 py-1 text-sm',
                            tab === t
                                ? 'bg-background font-medium shadow-sm'
                                : 'text-muted-foreground',
                        ]"
                        @click="tab = t"
                    >
                        {{
                            t === 'jobs'
                                ? 'Jobs'
                                : `Achievements ${unlockedCount}/${progress.achievements.length}`
                        }}
                    </button>
                </div>
                <Panel v-if="tab === 'achievements'" title="Achievements">
                    <ul class="grid gap-2 sm:grid-cols-2">
                        <li
                            v-for="a in progress.achievements"
                            :key="a.key"
                            :class="[
                                'flex items-start gap-3 rounded-lg border p-3',
                                a.unlocked_at ? '' : 'opacity-60',
                            ]"
                            :data-test="`achievement-${a.key}`"
                            :data-unlocked="a.unlocked_at ? 'true' : 'false'"
                        >
                            <Trophy
                                v-if="a.unlocked_at"
                                class="mt-0.5 size-5 shrink-0 text-amber-500"
                            />
                            <Lock
                                v-else
                                class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                            />
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ a.title }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ a.description }}
                                </p>
                                <p
                                    v-if="a.unlocked_at"
                                    class="pt-1 text-xs text-muted-foreground"
                                >
                                    Unlocked {{ formatDate(a.unlocked_at) }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </Panel>
                <Panel
                    v-else
                    title="Jobs"
                    :description="
                        isOwner ? 'Hidden jobs show only to you' : undefined
                    "
                >
                    <JobList
                        :jobs="jobs.data"
                        :show-driver="false"
                        empty="No synced jobs yet."
                    />
                    <Pagination
                        :links="jobs.links"
                        :last-page="jobs.last_page"
                        class="mt-3"
                    />
                </Panel>
            </div>
            <div class="flex flex-col gap-4">
                <Panel title="Right now">
                    <OnTheRoad
                        :statuses="live ? [live] : []"
                        :show-driver="false"
                        empty="Not on the road."
                    />
                </Panel>
                <Panel title="Trucks">
                    <p
                        v-if="trucks.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        No trucks yet.
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="truck in trucks"
                            :key="truck.name"
                            class="flex justify-between gap-2 py-2 text-sm"
                        >
                            <span class="font-medium">{{ truck.name }}</span>
                            <span class="text-muted-foreground tabular-nums"
                                >{{ formatKm(truck.distance_km) }} ·
                                {{ plural(truck.deliveries, 'job') }}</span
                            >
                        </li>
                    </ul>
                </Panel>
                <Panel title="Cargo" description="Top cargo by jobs">
                    <CargoChart :cargo="charts.cargo" />
                </Panel>
            </div>
        </div>
    </div>
</template>
