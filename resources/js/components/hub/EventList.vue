<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, Users } from '@lucide/vue';
import { formatEventTime } from '@/lib/format';
import { show } from '@/routes/events';
import type { EventSummary } from '@/types/hub';

// Upcoming company events (#4648): the /events page and the company page's panel.
withDefaults(
    defineProps<{
        events: EventSummary[];
        showCompany?: boolean;
        empty?: string;
    }>(),
    { showCompany: true, empty: 'No upcoming events.' },
);
</script>

<template>
    <p
        v-if="events.length === 0"
        class="py-6 text-center text-sm text-muted-foreground"
    >
        {{ empty }}
    </p>
    <ul v-else class="divide-y" data-test="event-list">
        <li
            v-for="event in events"
            :key="event.id"
            class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:gap-4"
            data-test="event-row"
        >
            <div class="min-w-0 flex-1">
                <Link
                    :href="show(event.id)"
                    class="font-medium hover:underline"
                    >{{ event.title }}</Link
                >
                <p
                    class="flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                >
                    <template v-if="showCompany && event.company"
                        >[{{ event.company.tag }}] {{ event.company.name }} ·
                    </template>
                    <span>{{ event.start_city }}</span>
                    <ArrowRight class="size-3.5" />
                    <span>{{ event.dest_city }}</span>
                    <template v-if="event.server">
                        · {{ event.server }}</template
                    >
                </p>
            </div>
            <div
                class="flex items-center gap-4 text-sm text-muted-foreground sm:justify-end"
            >
                <span class="inline-flex items-center gap-1 tabular-nums">
                    <Users class="size-4" />
                    {{ event.going
                    }}<template v-if="event.max_drivers"
                        >/{{ event.max_drivers }}</template
                    >
                    going
                </span>
                <time
                    :datetime="event.starts_at"
                    class="inline-flex items-center gap-1 font-medium text-foreground"
                >
                    <CalendarDays class="size-4" />
                    {{ formatEventTime(event.starts_at) }}
                </time>
            </div>
        </li>
    </ul>
</template>
