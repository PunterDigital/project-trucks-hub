<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EventList from '@/components/hub/EventList.vue';
import Pagination from '@/components/hub/Pagination.vue';
import { index } from '@/routes/events';
import type { EventSummary, Paginated } from '@/types/hub';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Events', href: index() }] },
});

defineProps<{
    events: Paginated<EventSummary>;
}>();
</script>

<template>
    <Head title="Events" />

    <div class="flex flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold">Upcoming events</h1>
            <p class="text-sm text-muted-foreground">
                Convoys and events from every company, in your local time.
            </p>
        </div>
        <section class="rounded-xl border px-4 sm:px-5">
            <EventList
                :events="events.data"
                empty="No upcoming events yet. Company owners and managers can add one from their company page."
            />
        </section>
        <Pagination :links="events.links" :last-page="events.last_page" />
    </div>
</template>
