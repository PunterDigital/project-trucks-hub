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
    formatCount,
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
        declared_income: number;
        game_revenue: number | null;
        earned_xp: number | null;
        cargo_damage: number | null;
        costs: number | null;
        cancel_penalty: number | null;
        started_at: string;
        source: 'TELEMETRY' | 'MANUAL';
        review_reasons: string[];
        review_note: string | null;
        review_time: string;
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
                        :review="job.review"
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
        <div
            v-if="job.review === 'pending'"
            class="space-y-1 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
            data-test="review-pending-note"
        >
            <p class="font-medium">Pending review</p>
            <p>
                This job was flagged ({{ job.review_reasons.join('; ') }}), so
                Hub staff will check it. Until then only you can see it, and it
                doesn't count towards stats or leaderboards.
            </p>
            <p class="text-muted-foreground">{{ job.review_time }}</p>
        </div>
        <div
            v-else-if="job.review === 'rejected'"
            class="space-y-1 rounded-lg border border-red-500/40 bg-red-500/5 p-3 text-sm"
            data-test="review-rejected-note"
        >
            <p class="font-medium">Rejected by staff</p>
            <p>
                <template v-if="job.review_note"
                    >{{ job.review_note }}.
                </template>
                Only you can see this job, and it doesn't count towards stats or
                leaderboards.
            </p>
        </div>

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
                        <dd class="font-medium" data-test="job-income">
                            {{ formatMoney(job.income) }}
                        </dd>
                        <dd
                            v-if="
                                job.game_revenue !== null &&
                                job.game_revenue !== job.declared_income
                            "
                            class="text-xs text-muted-foreground"
                            data-test="job-offered"
                        >
                            Offered {{ formatMoney(job.declared_income) }}
                        </dd>
                    </div>
                    <div v-if="job.costs !== null">
                        <dt class="text-xs text-muted-foreground">
                            Tolls, fines and fares
                        </dt>
                        <dd class="font-medium" data-test="job-costs">
                            {{ formatMoney(job.costs) }}
                        </dd>
                    </div>
                    <div v-if="job.cancel_penalty !== null">
                        <dt class="text-xs text-muted-foreground">
                            Cancellation penalty
                        </dt>
                        <dd class="font-medium" data-test="job-cancel-penalty">
                            {{ formatMoney(job.cancel_penalty) }}
                        </dd>
                    </div>
                    <div v-if="job.earned_xp !== null">
                        <dt class="text-xs text-muted-foreground">XP</dt>
                        <dd class="font-medium" data-test="job-xp">
                            {{ formatCount(job.earned_xp) }}
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
                    <div v-if="job.cargo_damage !== null">
                        <dt class="text-xs text-muted-foreground">
                            Cargo damage
                        </dt>
                        <dd class="font-medium" data-test="job-cargo-damage">
                            {{ (job.cargo_damage * 100).toFixed(1) }}%
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
