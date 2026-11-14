<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import DriverLink from '@/components/hub/DriverLink.vue';
import Pagination from '@/components/hub/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCount, formatKm } from '@/lib/format';
import { index } from '@/routes/drivers';
import type { DriverSummary, Paginated } from '@/types/hub';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Drivers', href: index() }] },
});

defineProps<{
    drivers: Paginated<
        DriverSummary & { deliveries: number; distance_km: number }
    >;
    search: string;
}>();
</script>

<template>
    <Head title="Drivers" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <h1 class="flex-1 text-xl font-semibold">Drivers</h1>
            <Form v-bind="index.form()" class="flex gap-2">
                <Input
                    name="search"
                    :default-value="search"
                    placeholder="Search drivers"
                    aria-label="Search drivers"
                    class="w-56"
                />
                <Button type="submit" variant="outline" size="icon">
                    <Search class="size-4" /><span class="sr-only">Search</span>
                </Button>
            </Form>
        </div>

        <p
            v-if="drivers.data.length === 0"
            class="py-10 text-center text-sm text-muted-foreground"
        >
            No drivers found.
        </p>
        <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="driver in drivers.data"
                :key="driver.username ?? driver.name"
                class="flex items-center gap-3 rounded-xl border p-4"
                data-test="driver-card"
            >
                <DriverLink :driver="driver" size="md" class="flex-1" />
                <div class="text-right text-sm tabular-nums">
                    <div>{{ formatKm(driver.distance_km) }}</div>
                    <div class="text-muted-foreground">
                        {{ formatCount(driver.deliveries) }} jobs
                    </div>
                </div>
            </li>
        </ul>

        <Pagination :links="drivers.links" :last-page="drivers.last_page" />
    </div>
</template>
