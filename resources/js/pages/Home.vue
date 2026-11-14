<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import JobList from '@/components/hub/JobList.vue';
import OnTheRoad from '@/components/hub/OnTheRoad.vue';
import Panel from '@/components/hub/Panel.vue';
import { Button } from '@/components/ui/button';
import { formatCount, formatKm } from '@/lib/format';
import { dashboard, leaderboards, register } from '@/routes';
import type {
    DriverSummary,
    JobSummary,
    LeaderboardRow,
    LiveSummary,
    Totals,
} from '@/types/hub';

defineProps<{
    canRegister: boolean;
    steamEnabled: boolean;
    totals: Totals & { drivers: number; companies: number };
    onTheRoad: LiveSummary[];
    latest: JobSummary[];
    topDrivers: LeaderboardRow[];
    downloadUrl: string;
}>();

const page = usePage();
const signedIn = computed(() => page.props.auth.user !== null);
</script>

<template>
    <Head title="Home" />

    <div class="flex flex-col gap-4 p-4">
        <section
            class="overflow-hidden rounded-xl border bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white sm:p-8"
        >
            <div class="flex flex-col gap-6 md:flex-row md:items-center">
                <div class="flex-1 space-y-3">
                    <h1 class="text-2xl font-semibold sm:text-3xl">
                        Every delivery, shared.
                    </h1>
                    <p class="max-w-xl text-slate-300">
                        ProjectTrucks logs your Euro Truck Simulator 2 jobs on
                        your PC. Link it to the Hub and they sync here
                        automatically: see what other drivers are hauling, join
                        a virtual trucking company and climb the leaderboards.
                    </p>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <Button
                            v-if="!signedIn && canRegister"
                            as-child
                            class="bg-amber-500 text-slate-950 hover:bg-amber-400"
                        >
                            <Link :href="register()">Create an account</Link>
                        </Button>
                        <Button
                            v-else-if="signedIn"
                            as-child
                            class="bg-amber-500 text-slate-950 hover:bg-amber-400"
                        >
                            <Link :href="dashboard()">My dashboard</Link>
                        </Button>
                        <Button
                            as-child
                            variant="outline"
                            class="border-slate-600 bg-transparent text-white hover:bg-slate-700 hover:text-white"
                        >
                            <a :href="downloadUrl"
                                ><Download class="size-4" /> Get
                                ProjectTrucks</a
                            >
                        </Button>
                    </div>
                </div>
                <img
                    src="/favicon.svg"
                    alt=""
                    class="hidden size-32 shrink-0 md:block"
                />
            </div>
            <dl
                class="mt-6 grid grid-cols-2 gap-4 border-t border-slate-700 pt-4 sm:grid-cols-4"
            >
                <div>
                    <dt class="text-xs text-slate-400">Drivers</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatCount(totals.drivers) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Companies</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatCount(totals.companies) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Deliveries</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatCount(totals.deliveries) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Driven</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatKm(totals.distance_km) }}
                    </dd>
                </div>
            </dl>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel
                title="Latest deliveries"
                description="Jobs drivers have just finished"
                class="lg:col-span-2"
            >
                <JobList
                    :jobs="latest"
                    empty="No deliveries yet. Link ProjectTrucks and yours could be first."
                />
            </Panel>

            <div class="flex flex-col gap-4">
                <Panel title="On the road now">
                    <OnTheRoad :statuses="onTheRoad" />
                </Panel>

                <Panel title="Top drivers this week">
                    <template #actions>
                        <Link
                            :href="leaderboards()"
                            class="text-sm text-muted-foreground hover:underline"
                            >All</Link
                        >
                    </template>
                    <p
                        v-if="topDrivers.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        No deliveries this week yet.
                    </p>
                    <ol v-else class="space-y-2">
                        <li
                            v-for="row in topDrivers"
                            :key="row.rank"
                            class="flex items-center gap-3"
                        >
                            <span
                                class="w-5 text-right text-sm font-semibold text-muted-foreground tabular-nums"
                                >{{ row.rank }}</span
                            >
                            <DriverLink
                                :driver="row.subject as DriverSummary"
                                class="flex-1"
                            />
                            <span class="text-sm tabular-nums">{{
                                formatKm(row.distance_km)
                            }}</span>
                        </li>
                    </ol>
                </Panel>
            </div>
        </div>
    </div>
</template>
