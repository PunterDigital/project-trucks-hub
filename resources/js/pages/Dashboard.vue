<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, Link2, MonitorSmartphone } from '@lucide/vue';
import JobList from '@/components/hub/JobList.vue';
import OnTheRoad from '@/components/hub/OnTheRoad.vue';
import Panel from '@/components/hub/Panel.vue';
import StatGrid from '@/components/hub/StatGrid.vue';
import { Button } from '@/components/ui/button';
import { plural } from '@/lib/format';
import { dashboard } from '@/routes';
import {
    create as createCompany,
    index as companies,
    show as showCompany,
} from '@/routes/companies';
import { index as devices } from '@/routes/devices';
import { show as showDriver } from '@/routes/drivers';
import type {
    CompanyRole,
    DriverSummary,
    JobSummary,
    LiveSummary,
    PeriodTotals,
} from '@/types/hub';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My dashboard', href: dashboard() }],
    },
});

defineProps<{
    driver: DriverSummary;
    role: CompanyRole | null;
    linkedApps: number;
    jobsSynced: number;
    stats: PeriodTotals;
    live: LiveSummary | null;
    recent: JobSummary[];
    downloadUrl: string;
}>();
</script>

<template>
    <Head title="My dashboard" />

    <div class="flex flex-col gap-4 p-4">
        <section
            v-if="linkedApps === 0"
            class="flex flex-col gap-4 rounded-xl border border-amber-500/40 bg-amber-500/5 p-5 sm:flex-row sm:items-center"
            data-test="link-app-callout"
        >
            <Link2 class="size-8 shrink-0 text-amber-600" />
            <div class="flex-1">
                <h2 class="font-semibold">
                    Link ProjectTrucks to start syncing
                </h2>
                <p class="text-sm text-muted-foreground">
                    In ProjectTrucks open
                    <strong>Settings → ProjectTrucks Hub</strong> and click
                    <strong>Link account</strong>. It shows a code: approve it
                    here and your new jobs appear on your profile automatically.
                    Nothing is uploaded until you do.
                </p>
            </div>
            <Button as-child variant="outline">
                <a :href="downloadUrl">Get ProjectTrucks</a>
            </Button>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <Panel title="My stats">
                    <template #actions>
                        <Link
                            v-if="driver.username"
                            :href="showDriver(driver.username)"
                            class="text-sm text-muted-foreground hover:underline"
                            >Public profile</Link
                        >
                    </template>
                    <StatGrid :stats="stats" />
                </Panel>

                <Panel title="My recent jobs">
                    <JobList
                        :jobs="recent"
                        :show-driver="false"
                        empty="No synced jobs yet."
                    />
                </Panel>
            </div>

            <div class="flex flex-col gap-4">
                <Panel title="Right now">
                    <OnTheRoad
                        :statuses="live ? [live] : []"
                        :show-driver="false"
                        empty="Not driving. Start ETS2 with ProjectTrucks running and it shows here."
                    />
                </Panel>

                <Panel title="Company">
                    <div v-if="driver.company" class="flex items-center gap-3">
                        <Building2 class="size-5 text-muted-foreground" />
                        <div class="flex-1">
                            <Link
                                :href="showCompany(driver.company.slug)"
                                class="font-medium hover:underline"
                                >[{{ driver.company.tag }}]
                                {{ driver.company.name }}</Link
                            >
                            <p class="text-sm text-muted-foreground capitalize">
                                {{ role }}
                            </p>
                        </div>
                    </div>
                    <div v-else class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            You aren't in a company. Join one or start your own.
                        </p>
                        <div class="flex gap-2">
                            <Button as-child size="sm" variant="outline">
                                <Link :href="companies()"
                                    >Browse companies</Link
                                >
                            </Button>
                            <Button as-child size="sm">
                                <Link :href="createCompany()">Create one</Link>
                            </Button>
                        </div>
                    </div>
                </Panel>

                <Panel title="Linked apps">
                    <div class="flex items-center gap-3">
                        <MonitorSmartphone
                            class="size-5 text-muted-foreground"
                        />
                        <p class="flex-1 text-sm">
                            {{ linkedApps }} linked ·
                            {{ plural(jobsSynced, 'job') }}
                            synced
                        </p>
                        <Link
                            :href="devices()"
                            class="text-sm text-muted-foreground hover:underline"
                            >Manage</Link
                        >
                    </div>
                </Panel>
            </div>
        </div>
    </div>
</template>
