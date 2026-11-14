<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ExternalLink, Lock } from '@lucide/vue';
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
    CompanyRole,
    DriverSummary,
    JobSummary,
    LiveSummary,
    Paginated,
    PeriodTotals,
} from '@/types/hub';

defineProps<{
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
    live: LiveSummary | null;
    jobs: Paginated<JobSummary>;
}>();

const signedIn = usePage().props.auth.user !== null;
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
                <p v-if="driver.company" class="text-sm">
                    <span class="capitalize">{{ driver.role }}</span> at
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
                <Panel
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
            </div>
        </div>
    </div>
</template>
