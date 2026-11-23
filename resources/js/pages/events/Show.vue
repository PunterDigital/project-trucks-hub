<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    CalendarPlus,
    ExternalLink,
    Pencil,
    Users,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import Panel from '@/components/hub/Panel.vue';
import { Button } from '@/components/ui/button';
import { formatEventTime } from '@/lib/format';
import { login } from '@/routes';
import { show as showCompany } from '@/routes/companies';
import { calendar, cancel, edit, index, rsvp } from '@/routes/events';
import type { DriverSummary, EventSummary, RsvpStatus } from '@/types/hub';

// One company event (#4648).
const props = defineProps<{
    event: EventSummary & {
        start_company: string | null;
        dest_company: string | null;
        route_notes: string | null;
        truckersmp_url: string | null;
        route_image_url: string | null;
        over: boolean;
        full: boolean;
    };
    attendees: {
        going: DriverSummary[];
        maybe: DriverSummary[];
        not_going: number;
    };
    viewer: {
        signedIn: boolean;
        canAnswer: boolean;
        canManage: boolean;
        rsvp: RsvpStatus | null;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Events', href: index() }] },
});

const games: Record<string, string> = {
    ets2: 'Euro Truck Simulator 2',
    ats: 'American Truck Simulator',
};
const answers: { value: RsvpStatus; label: string }[] = [
    { value: 'going', label: 'Going' },
    { value: 'maybe', label: 'Maybe' },
    { value: 'not_going', label: 'Not going' },
];
const taking = computed(() => !props.event.cancelled && !props.event.over);

function answer(status: RsvpStatus) {
    router.post(rsvp(props.event.id).url, { status }, { preserveScroll: true });
}

function cancelEvent() {
    router.post(cancel(props.event.id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="event.title" />

    <div class="flex flex-col gap-4 p-4">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1 space-y-1">
                <h1 class="text-2xl font-semibold">{{ event.title }}</h1>
                <p v-if="event.company" class="text-sm text-muted-foreground">
                    <Link
                        :href="showCompany(event.company.slug)"
                        class="font-medium text-foreground hover:underline"
                        >[{{ event.company.tag }}]
                        {{ event.company.name }}</Link
                    >
                    · {{ games[event.game] ?? event.game }}
                    <template v-if="event.server">
                        · {{ event.server }}</template
                    >
                </p>
                <p
                    class="flex items-center gap-1.5 font-medium"
                    data-test="event-time"
                >
                    <CalendarDays class="size-4 text-muted-foreground" />
                    <time :datetime="event.starts_at">{{
                        formatEventTime(event.starts_at)
                    }}</time>
                    <span class="text-xs font-normal text-muted-foreground"
                        >(your time)</span
                    >
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <a :href="calendar(event.id).url" data-test="ics"
                        ><CalendarPlus class="size-4" /> Add to calendar</a
                    >
                </Button>
                <Button
                    v-if="event.truckersmp_url"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <a
                        :href="event.truckersmp_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="truckersmp-link"
                        ><ExternalLink class="size-4" /> On TruckersMP</a
                    >
                </Button>
                <template v-if="viewer.canManage && !event.cancelled">
                    <Button as-child variant="outline" size="sm">
                        <Link :href="edit(event.id)" data-test="edit-event"
                            ><Pencil class="size-4" /> Edit</Link
                        >
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="cancel-event"
                        @click="cancelEvent"
                        ><X class="size-4" /> Cancel event</Button
                    >
                </template>
            </div>
        </header>

        <p
            v-if="event.cancelled"
            class="rounded-lg border border-red-500/40 bg-red-500/5 p-3 text-sm"
            data-test="event-cancelled"
        >
            This event was cancelled.
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <Panel title="Route" class="lg:col-span-2">
                <p class="flex flex-wrap items-center gap-x-2 font-medium">
                    <span
                        >{{ event.start_city
                        }}<span
                            v-if="event.start_company"
                            class="font-normal text-muted-foreground"
                        >
                            ({{ event.start_company }})</span
                        ></span
                    >
                    <ArrowRight class="size-4 text-muted-foreground" />
                    <span
                        >{{ event.dest_city
                        }}<span
                            v-if="event.dest_company"
                            class="font-normal text-muted-foreground"
                        >
                            ({{ event.dest_company }})</span
                        ></span
                    >
                </p>
                <p
                    v-if="event.route_notes"
                    class="mt-3 text-sm break-words whitespace-pre-line"
                >
                    {{ event.route_notes }}
                </p>
                <a
                    v-if="event.route_image_url"
                    :href="event.route_image_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-3 block"
                    data-test="route-image"
                >
                    <img
                        :src="event.route_image_url"
                        alt="Route map"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        class="max-h-96 w-full rounded-lg border object-contain"
                    />
                </a>
            </Panel>

            <Panel title="Are you coming?">
                <div v-if="!taking" class="text-sm text-muted-foreground">
                    {{
                        event.cancelled
                            ? 'Answers are closed.'
                            : 'This event has started.'
                    }}
                </div>
                <div v-else-if="viewer.canAnswer" class="space-y-2">
                    <div
                        class="flex flex-wrap gap-2"
                        role="group"
                        aria-label="Your answer"
                    >
                        <Button
                            v-for="option in answers"
                            :key="option.value"
                            size="sm"
                            :variant="
                                viewer.rsvp === option.value
                                    ? 'default'
                                    : 'outline'
                            "
                            :aria-pressed="viewer.rsvp === option.value"
                            :disabled="
                                option.value === 'going' &&
                                event.full &&
                                viewer.rsvp !== 'going'
                            "
                            :data-test="`rsvp-${option.value}`"
                            @click="answer(option.value)"
                            >{{ option.label }}</Button
                        >
                    </div>
                    <p
                        v-if="event.full && viewer.rsvp !== 'going'"
                        class="text-xs text-muted-foreground"
                    >
                        This event is full.
                    </p>
                </div>
                <p
                    v-else-if="viewer.signedIn"
                    class="text-sm text-muted-foreground"
                    data-test="join-to-attend"
                >
                    <Link
                        v-if="event.company"
                        :href="showCompany(event.company.slug)"
                        class="font-medium text-foreground underline"
                        >Join {{ event.company.name }}</Link
                    >
                    to attend.
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    <Link
                        :href="login()"
                        class="font-medium text-foreground underline"
                        >Log in</Link
                    >
                    to say whether you're coming.
                </p>
            </Panel>
        </div>

        <Panel
            :title="`Going (${attendees.going.length}${event.max_drivers ? ` of ${event.max_drivers}` : ''})`"
        >
            <template #actions>
                <span
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground"
                >
                    <Users class="size-4" />
                    {{ attendees.maybe.length }} maybe ·
                    {{ attendees.not_going }} not going
                </span>
            </template>
            <p
                v-if="
                    attendees.going.length === 0 && attendees.maybe.length === 0
                "
                class="text-sm text-muted-foreground"
            >
                Nobody has answered yet.
            </p>
            <ul class="grid gap-2 sm:grid-cols-2" data-test="going">
                <li v-for="(driver, i) in attendees.going" :key="`g${i}`">
                    <DriverLink :driver="driver" />
                </li>
            </ul>
            <template v-if="attendees.maybe.length > 0">
                <h3 class="mt-4 mb-2 text-sm font-medium">Maybe</h3>
                <ul class="grid gap-2 sm:grid-cols-2" data-test="maybe">
                    <li v-for="(driver, i) in attendees.maybe" :key="`m${i}`">
                        <DriverLink :driver="driver" />
                    </li>
                </ul>
            </template>
        </Panel>
    </div>
</template>
