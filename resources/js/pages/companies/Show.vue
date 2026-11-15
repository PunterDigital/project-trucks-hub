<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { CalendarPlus, Settings } from '@lucide/vue';
import { computed } from 'vue';
import CompanyLogo from '@/components/hub/CompanyLogo.vue';
import DriverAvatar from '@/components/hub/DriverAvatar.vue';
import EventList from '@/components/hub/EventList.vue';
import JobList from '@/components/hub/JobList.vue';
import OnTheRoad from '@/components/hub/OnTheRoad.vue';
import Panel from '@/components/hub/Panel.vue';
import ReportButton from '@/components/hub/ReportButton.vue';
import RecruitmentBadge from '@/components/hub/RecruitmentBadge.vue';
import StatGrid from '@/components/hub/StatGrid.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatKm, timeAgo } from '@/lib/format';
import { login } from '@/routes';
import { apply, edit, index, join, leave, withdraw } from '@/routes/companies';
import { decide } from '@/routes/companies/applications';
import {
    destroy as removeMember,
    update as updateMember,
} from '@/routes/companies/members';
import { show as showDriver } from '@/routes/drivers';
import { create as createEvent } from '@/routes/companies/events';
import type {
    CompanyRole,
    CompanySummary,
    EventSummary,
    JobSummary,
    LiveSummary,
    PeriodTotals,
    Recruitment,
} from '@/types/hub';

type Member = {
    id: number;
    role: CompanyRole;
    joined_at: string | null;
    driver: { name: string; username: string | null; avatar: string | null };
    month: { deliveries: number; distance_km: number };
    on_the_road: boolean;
};

const props = defineProps<{
    company: CompanySummary & {
        description: string | null;
        recruitment: Recruitment;
        banner: string | null;
        created_at: string | null;
    };
    role: CompanyRole | null;
    viewer: {
        signedIn: boolean;
        inOtherCompany: boolean;
        pendingApplication: number | null;
    };
    stats: PeriodTotals;
    members: Member[];
    onTheRoad: LiveSummary[];
    latest: JobSummary[];
    events: EventSummary[];
    applications: {
        id: number;
        message: string | null;
        created_at: string | null;
        driver: {
            name: string;
            username: string | null;
            avatar: string | null;
        };
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Companies', href: index() }] },
});

const isOwner = computed(() => props.role === 'owner');
const canManage = computed(
    () => props.role === 'owner' || props.role === 'manager',
);

function canRemove(member: Member): boolean {
    if (member.role === 'owner') {
        return false;
    }

    return (
        isOwner.value || (props.role === 'manager' && member.role === 'driver')
    );
}

function setRole(member: Member, role: CompanyRole) {
    if (
        role === 'owner' &&
        !confirm(
            `Make ${member.driver.name} the owner? You'll become a manager.`,
        )
    ) {
        return;
    }

    router.patch(
        updateMember([props.company.slug, member.id]).url,
        { role },
        { preserveScroll: true },
    );
}

function remove(member: Member) {
    if (confirm(`Remove ${member.driver.name} from ${props.company.name}?`)) {
        router.delete(removeMember([props.company.slug, member.id]).url, {
            preserveScroll: true,
        });
    }
}

function leaveCompany() {
    if (confirm(`Leave ${props.company.name}?`)) {
        router.post(leave(props.company.slug).url);
    }
}
</script>

<template>
    <Head :title="company.name" />

    <div class="flex flex-col gap-4 p-4">
        <img
            v-if="company.banner"
            :src="company.banner"
            :alt="`${company.name} banner`"
            class="aspect-[15/4] w-full rounded-xl object-cover"
            data-test="company-banner"
        />
        <header class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <CompanyLogo :company="company" class="size-16 text-sm" />
            <div class="flex-1 space-y-1">
                <h1
                    class="flex flex-wrap items-center gap-2 text-2xl font-semibold"
                >
                    <span class="text-muted-foreground"
                        >[{{ company.tag }}]</span
                    >
                    {{ company.name }}
                    <RecruitmentBadge :recruitment="company.recruitment" />
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ members.length }}
                    {{ members.length === 1 ? 'member' : 'members' }} · founded
                    {{ formatDate(company.created_at) }}
                </p>
                <p
                    v-if="company.description"
                    class="max-w-2xl pt-1 text-sm whitespace-pre-line"
                >
                    {{ company.description }}
                </p>
            </div>

            <div
                class="flex shrink-0 flex-wrap gap-2"
                data-test="company-actions"
            >
                <Button v-if="canManage" as-child variant="outline" size="sm">
                    <Link :href="edit(company.slug)"
                        ><Settings class="size-4" /> Edit</Link
                    >
                </Button>
                <Button
                    v-if="role && role !== 'owner'"
                    variant="outline"
                    size="sm"
                    @click="leaveCompany"
                    >Leave company</Button
                >

                <template v-if="!role">
                    <Button
                        v-if="
                            !viewer.signedIn && company.recruitment !== 'closed'
                        "
                        as-child
                        size="sm"
                    >
                        <Link :href="login()">Log in to join</Link>
                    </Button>
                    <p
                        v-else-if="viewer.inOtherCompany"
                        class="text-sm text-muted-foreground"
                    >
                        You're in another company.
                    </p>
                    <Form
                        v-else-if="company.recruitment === 'open'"
                        v-bind="join.form(company.slug)"
                        v-slot="{ processing }"
                    >
                        <Button
                            size="sm"
                            :disabled="processing"
                            data-test="join"
                            >Join company</Button
                        >
                    </Form>
                    <Form
                        v-else-if="viewer.pendingApplication"
                        v-bind="withdraw.form(company.slug)"
                        v-slot="{ processing }"
                        class="flex items-center gap-2"
                    >
                        <span class="text-sm text-muted-foreground"
                            >Application sent</span
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="processing"
                            >Withdraw</Button
                        >
                    </Form>
                </template>
                <ReportButton
                    v-if="viewer.signedIn && !role"
                    type="company"
                    :id="company.id!"
                    what="this company"
                />
            </div>
        </header>

        <Panel
            v-if="
                !role &&
                viewer.signedIn &&
                !viewer.inOtherCompany &&
                !viewer.pendingApplication &&
                company.recruitment === 'apply'
            "
            title="Apply to join"
        >
            <Form
                v-bind="apply.form(company.slug)"
                class="flex flex-col gap-3"
                v-slot="{ processing }"
            >
                <textarea
                    name="message"
                    rows="3"
                    maxlength="1000"
                    aria-label="Message to the company"
                    placeholder="Say hello: where you drive, how often you play…"
                    class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <Button
                    class="self-start"
                    :disabled="processing"
                    data-test="apply"
                    >Send application</Button
                >
            </Form>
        </Panel>

        <Panel
            v-if="canManage && applications.length > 0"
            :title="`Applications (${applications.length})`"
        >
            <ul class="divide-y">
                <li
                    v-for="application in applications"
                    :key="application.id"
                    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center"
                    data-test="application"
                >
                    <div class="flex flex-1 items-start gap-3">
                        <DriverAvatar
                            :name="application.driver.name"
                            :avatar="application.driver.avatar"
                        />
                        <div>
                            <Link
                                v-if="application.driver.username"
                                :href="showDriver(application.driver.username)"
                                class="font-medium hover:underline"
                                >{{ application.driver.name }}</Link
                            >
                            <span class="ml-2 text-xs text-muted-foreground">{{
                                timeAgo(application.created_at)
                            }}</span>
                            <p
                                v-if="application.message"
                                class="text-sm whitespace-pre-line text-muted-foreground"
                            >
                                {{ application.message }}
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Form
                            v-bind="decide.form([company.slug, application.id])"
                            v-slot="{ processing }"
                        >
                            <input
                                type="hidden"
                                name="decision"
                                value="accept"
                            />
                            <Button size="sm" :disabled="processing"
                                >Accept</Button
                            >
                        </Form>
                        <Form
                            v-bind="decide.form([company.slug, application.id])"
                            v-slot="{ processing }"
                        >
                            <input
                                type="hidden"
                                name="decision"
                                value="decline"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="processing"
                                >Decline</Button
                            >
                        </Form>
                    </div>
                </li>
            </ul>
        </Panel>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <Panel title="Upcoming events" data-test="company-events">
                    <template v-if="canManage" #actions>
                        <Button as-child variant="outline" size="sm">
                            <Link
                                :href="createEvent(company.slug)"
                                data-test="new-event"
                                ><CalendarPlus class="size-4" /> New event</Link
                            >
                        </Button>
                    </template>
                    <EventList
                        :events="events"
                        :show-company="false"
                        empty="No upcoming events."
                    />
                </Panel>
                <Panel title="Company stats">
                    <StatGrid :stats="stats" />
                </Panel>
                <Panel title="Latest deliveries">
                    <JobList
                        :jobs="latest"
                        empty="No deliveries for this company yet."
                    />
                </Panel>
            </div>
            <div class="flex flex-col gap-4">
                <Panel title="On the road">
                    <OnTheRoad
                        :statuses="onTheRoad"
                        empty="Nobody is driving right now."
                    />
                </Panel>
                <Panel title="Members" description="Distance this month">
                    <ul class="divide-y" data-test="members">
                        <li
                            v-for="member in members"
                            :key="member.id"
                            class="flex items-center gap-3 py-2"
                            data-test="member"
                        >
                            <DriverAvatar
                                :name="member.driver.name"
                                :avatar="member.driver.avatar"
                            />
                            <div class="min-w-0 flex-1">
                                <Link
                                    v-if="member.driver.username"
                                    :href="showDriver(member.driver.username)"
                                    class="block truncate text-sm font-medium hover:underline"
                                    >{{ member.driver.name }}</Link
                                >
                                <span
                                    v-else
                                    class="block truncate text-sm text-muted-foreground"
                                    >{{ member.driver.name }}</span
                                >
                                <span
                                    class="text-xs text-muted-foreground capitalize"
                                >
                                    {{ member.role }}
                                    <template v-if="member.on_the_road">
                                        ·
                                        <span class="text-green-600"
                                            >driving</span
                                        ></template
                                    >
                                </span>
                            </div>
                            <span
                                class="text-sm text-muted-foreground tabular-nums"
                                >{{ formatKm(member.month.distance_km) }}</span
                            >
                            <select
                                v-if="isOwner && member.role !== 'owner'"
                                :value="member.role"
                                class="rounded-md border bg-transparent px-1 py-0.5 text-xs"
                                :aria-label="`Role of ${member.driver.name}`"
                                @change="
                                    setRole(
                                        member,
                                        ($event.target as HTMLSelectElement)
                                            .value as CompanyRole,
                                    )
                                "
                            >
                                <option
                                    value="driver"
                                    :selected="member.role === 'driver'"
                                >
                                    Driver
                                </option>
                                <option
                                    value="manager"
                                    :selected="member.role === 'manager'"
                                >
                                    Manager
                                </option>
                                <option value="owner">Make owner</option>
                            </select>
                            <button
                                v-if="canRemove(member)"
                                type="button"
                                class="text-xs text-red-600 hover:underline"
                                @click="remove(member)"
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                </Panel>
            </div>
        </div>
    </div>
</template>
