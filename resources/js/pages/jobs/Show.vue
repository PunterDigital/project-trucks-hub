<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Eye, EyeOff, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import JobStatusBadge from '@/components/hub/JobStatusBadge.vue';
import KudosButton from '@/components/hub/KudosButton.vue';
import Panel from '@/components/hub/Panel.vue';
import ReportButton from '@/components/hub/ReportButton.vue';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    formatDuration,
    formatKm,
    formatMoney,
    formatTonnes,
    timeAgo,
} from '@/lib/format';
import { login } from '@/routes';
import { show as showCompany } from '@/routes/companies';
import { update } from '@/routes/jobs';
import {
    destroy as destroyComment,
    store as storeComment,
} from '@/routes/jobs/comments';
import type { CompanySummary, JobComment, JobSummary } from '@/types/hub';

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
        hub_flag_reason: string | null;
        hub_held: boolean;
        game: string;
        company: CompanySummary | null;
    };
    isOwner: boolean;
    comments: JobComment[];
    commentMaxLength: number;
}>();

const signedIn = usePage().props.auth.user !== null;

// Comments (#4646).
const draft = ref('');

function deleteComment(comment: JobComment) {
    router.delete(
        destroyComment({ delivery: props.job.id, comment: comment.id }).url,
        { preserveScroll: true },
    );
}

// Same prefix as App\Admin\Moderation::STAFF_FLAG_PREFIX (#4651).
const STAFF_FLAG = 'Flagged by staff: ';

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
                <div class="flex flex-wrap items-center gap-2">
                    <JobStatusBadge
                        :status="job.status"
                        :flagged="job.flagged"
                        :hidden="job.hidden"
                    />
                    <KudosButton
                        :job-id="job.id"
                        :driver-id="job.driver_id"
                        :count="job.kudos"
                        :given="job.kudos_given"
                    />
                </div>
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
            <ReportButton
                v-else-if="signedIn"
                type="job"
                :id="job.id"
                what="this job"
            />
        </header>

        <p
            v-if="job.staff_hidden"
            class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
            data-test="staff-hidden"
        >
            Hub staff hid this job. Only you can see it, and it doesn't count
            towards stats or leaderboards.
        </p>
        <p
            v-if="job.flag_reason"
            class="rounded-lg border border-red-500/40 bg-red-500/5 p-3 text-sm"
            data-test="app-flag"
        >
            ProjectTrucks flagged this job ({{ job.flag_reason }}), so it
            doesn't count towards stats or leaderboards.
        </p>
        <p
            v-if="job.hub_flag_reason"
            class="rounded-lg border border-red-500/40 bg-red-500/5 p-3 text-sm"
            data-test="hub-flag"
        >
            <template v-if="job.hub_flag_reason.startsWith(STAFF_FLAG)">
                Hub staff flagged this job ({{
                    job.hub_flag_reason.slice(STAFF_FLAG.length)
                }}), so it doesn't count towards stats or leaderboards.
            </template>
            <template v-else-if="job.hub_held">
                The Hub is holding this job for review ({{
                    job.hub_flag_reason
                }}). Until then it doesn't count towards stats or leaderboards.
            </template>
            <template v-else>
                The Hub's checks flagged this job ({{ job.hub_flag_reason }}),
                so it doesn't count towards stats or leaderboards.
            </template>
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

        <Panel
            id="comments"
            :title="
                comments.length > 0
                    ? `Comments (${comments.length})`
                    : 'Comments'
            "
            class="scroll-mt-4"
        >
            <p
                v-if="comments.length === 0"
                class="pb-3 text-sm text-muted-foreground"
            >
                No comments yet.
            </p>
            <ul v-else class="mb-3 divide-y" data-test="comments">
                <li
                    v-for="comment in comments"
                    :key="comment.id"
                    class="flex gap-3 py-3"
                    data-test="comment"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-x-2">
                            <DriverLink :driver="comment.author" />
                            <time
                                :datetime="comment.created_at"
                                class="text-xs text-muted-foreground"
                                >{{ timeAgo(comment.created_at) }}</time
                            >
                        </div>
                        <p
                            class="text-sm break-words whitespace-pre-line"
                            data-test="comment-body"
                        >
                            {{ comment.body }}
                        </p>
                    </div>
                    <Button
                        v-if="comment.can_delete"
                        variant="ghost"
                        size="icon"
                        class="shrink-0 text-muted-foreground"
                        aria-label="Delete comment"
                        title="Delete comment"
                        data-test="delete-comment"
                        @click="deleteComment(comment)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>

            <Form
                v-if="signedIn"
                v-bind="storeComment.form(job.id)"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="flex flex-col gap-2"
                v-slot="{ errors, processing }"
                @success="draft = ''"
            >
                <textarea
                    v-model="draft"
                    name="body"
                    rows="3"
                    :maxlength="commentMaxLength"
                    aria-label="Write a comment"
                    placeholder="Say something nice about the run…"
                    class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    data-test="comment-input"
                />
                <InputError :message="errors.body" />
                <div class="flex items-center gap-3">
                    <Button
                        :disabled="processing || draft.trim() === ''"
                        data-test="post-comment"
                        >Post comment</Button
                    >
                    <span class="text-xs text-muted-foreground tabular-nums"
                        >{{ draft.length }} / {{ commentMaxLength }}</span
                    >
                </div>
            </Form>
            <p v-else class="text-sm text-muted-foreground">
                <Link
                    :href="login()"
                    class="font-medium text-foreground underline"
                    >Log in</Link
                >
                to give kudos or comment.
            </p>
        </Panel>
    </div>
</template>
