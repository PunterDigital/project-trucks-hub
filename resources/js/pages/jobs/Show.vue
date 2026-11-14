<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Eye, EyeOff } from '@lucide/vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import JobStatusBadge from '@/components/hub/JobStatusBadge.vue';
import Panel from '@/components/hub/Panel.vue';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    formatDuration,
    formatKm,
    formatMoney,
    formatTonnes,
} from '@/lib/format';
import { show as showCompany } from '@/routes/companies';
import { update } from '@/routes/jobs';
import type { CompanySummary, JobSummary } from '@/types/hub';

const props = defineProps<{
    job: JobSummary & {
        source_company: string;
        dest_company: string;
        duration_sec: number;
        avg_speed_kmh: number;
        fuel_used_l: number | null;
        damage: number | null;
        started_at: string;
        source: 'TELEMETRY' | 'MANUAL';
        flag_reason: string | null;
        game: string;
        company: CompanySummary | null;
    };
    isOwner: boolean;
}>();

function toggleHidden() {
    router.patch(
        update(props.job.id).url,
        { hidden: !props.job.hidden },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`${job.source_city} → ${job.dest_city}`" />

    <div class="flex flex-col gap-4 p-4">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1 space-y-1">
                <h1
                    class="flex flex-wrap items-center gap-2 text-2xl font-semibold"
                >
                    {{ job.source_city }}
                    <ArrowRight class="size-5 text-muted-foreground" />
                    {{ job.dest_city }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ job.source_company }} → {{ job.dest_company }} ·
                    {{ formatDateTime(job.completed_at) }}
                </p>
                <JobStatusBadge
                    :status="job.status"
                    :flagged="job.flagged"
                    :hidden="job.hidden"
                />
            </div>
            <Button
                v-if="isOwner"
                variant="outline"
                size="sm"
                data-test="toggle-hidden"
                @click="toggleHidden"
            >
                <component :is="job.hidden ? Eye : EyeOff" class="size-4" />
                {{ job.hidden ? 'Show to others' : 'Hide from others' }}
            </Button>
        </header>

        <p
            v-if="job.flagged && job.flag_reason"
            class="rounded-lg border border-red-500/40 bg-red-500/5 p-3 text-sm"
        >
            ProjectTrucks flagged this job ({{ job.flag_reason }}), so it
            doesn't count towards stats or leaderboards.
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Delivery" class="lg:col-span-2">
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-muted-foreground">Cargo</dt>
                        <dd class="font-medium">{{ job.cargo_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Weight</dt>
                        <dd class="font-medium">
                            {{ formatTonnes(job.cargo_mass_kg) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Income</dt>
                        <dd class="font-medium">
                            {{ formatMoney(job.income) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Distance</dt>
                        <dd class="font-medium">
                            {{ formatKm(job.distance_km) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Time</dt>
                        <dd class="font-medium">
                            {{ formatDuration(job.duration_sec) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Average speed
                        </dt>
                        <dd class="font-medium">
                            {{ Math.round(job.avg_speed_kmh) }} km/h
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Fuel</dt>
                        <dd class="font-medium">
                            {{
                                job.fuel_used_l === null
                                    ? '–'
                                    : `${Math.round(job.fuel_used_l)} l`
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Damage</dt>
                        <dd class="font-medium">
                            {{
                                job.damage === null
                                    ? '–'
                                    : `${(job.damage * 100).toFixed(1)}%`
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Truck</dt>
                        <dd class="font-medium">{{ job.truck ?? '–' }}</dd>
                    </div>
                </dl>
            </Panel>
            <Panel title="Driver">
                <div class="space-y-3">
                    <DriverLink
                        v-if="job.driver"
                        :driver="job.driver"
                        size="md"
                    />
                    <p v-if="job.company" class="text-sm text-muted-foreground">
                        Hauled for
                        <Link
                            :href="showCompany(job.company.slug)"
                            class="font-medium text-foreground hover:underline"
                            >[{{ job.company.tag }}]
                            {{ job.company.name }}</Link
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            job.source === 'TELEMETRY'
                                ? 'Recorded by ProjectTrucks from the game.'
                                : 'Entered by hand in ProjectTrucks.'
                        }}
                    </p>
                </div>
            </Panel>
        </div>
    </div>
</template>
