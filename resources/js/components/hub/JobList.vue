<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, MessageSquare } from '@lucide/vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import JobStatusBadge from '@/components/hub/JobStatusBadge.vue';
import KudosButton from '@/components/hub/KudosButton.vue';
import { formatKm, formatMoney, formatTonnes, timeAgo } from '@/lib/format';
import { show as showJob } from '@/routes/jobs';
import type { JobSummary } from '@/types/hub';

withDefaults(
    defineProps<{
        jobs: JobSummary[];
        showDriver?: boolean;
        empty?: string;
    }>(),
    { showDriver: true, empty: 'No jobs yet.' },
);
</script>

<template>
    <p
        v-if="jobs.length === 0"
        class="py-6 text-center text-sm text-muted-foreground"
    >
        {{ empty }}
    </p>
    <ul v-else class="divide-y" data-test="job-list">
        <li
            v-for="job in jobs"
            :key="job.id"
            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:gap-4"
            data-test="job-row"
        >
            <div
                v-if="showDriver && job.driver"
                class="w-full sm:w-48 sm:shrink-0"
            >
                <DriverLink :driver="job.driver" />
            </div>
            <div class="min-w-0 flex-1">
                <Link
                    :href="showJob(job.id)"
                    class="flex flex-wrap items-center gap-x-1.5 font-medium hover:underline"
                >
                    <span>{{ job.source_city }}</span>
                    <ArrowRight class="size-3.5 text-muted-foreground" />
                    <span>{{ job.dest_city }}</span>
                </Link>
                <p class="truncate text-sm text-muted-foreground">
                    {{ job.cargo_name }} · {{ formatTonnes(job.cargo_mass_kg) }}
                    <template v-if="job.truck"> · {{ job.truck }}</template>
                </p>
            </div>
            <div
                class="flex items-center justify-between gap-4 text-sm sm:justify-end"
            >
                <div class="flex items-center">
                    <KudosButton
                        :job-id="job.id"
                        :driver-id="job.driver_id"
                        :count="job.kudos"
                        :given="job.kudos_given"
                    />
                    <Link
                        v-if="job.comments > 0"
                        :href="`${showJob(job.id).url}#comments`"
                        :aria-label="`${job.comments} ${job.comments === 1 ? 'comment' : 'comments'}`"
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-muted-foreground tabular-nums hover:bg-accent"
                        data-test="comment-count"
                    >
                        <MessageSquare class="size-4" />
                        <span>{{ job.comments }}</span>
                    </Link>
                </div>
                <JobStatusBadge
                    :status="job.status"
                    :flagged="job.flagged"
                    :hidden="job.hidden"
                />
                <div class="text-right tabular-nums">
                    <div>{{ formatKm(job.distance_km) }}</div>
                    <div class="text-muted-foreground">
                        {{ formatMoney(job.income) }}
                    </div>
                </div>
                <time
                    :datetime="job.completed_at"
                    class="w-24 shrink-0 text-right text-xs text-muted-foreground"
                    >{{ timeAgo(job.completed_at) }}</time
                >
            </div>
        </li>
    </ul>
</template>
