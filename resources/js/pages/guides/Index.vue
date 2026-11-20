<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { index, show } from '@/routes/guides';
import type { GuideSummary } from '@/types/hub';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Guides', href: index() }] },
});

defineProps<{
    guides: GuideSummary[];
}>();
</script>

<template>
    <Head title="ETS2 guides" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Guides</h1>
            <p class="text-sm text-muted-foreground">
                How to get the most out of ProjectTrucks and the Hub in Euro
                Truck Simulator 2.
            </p>
        </div>

        <ul class="flex flex-col gap-3" data-test="guide-list">
            <li v-for="guide in guides" :key="guide.slug">
                <Link
                    :href="show(guide.slug).url"
                    class="group flex flex-col gap-1 rounded-xl border p-4 transition-colors hover:bg-muted/50"
                >
                    <h2 class="text-lg font-semibold group-hover:underline">
                        {{ guide.title }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ guide.description }}
                    </p>
                </Link>
            </li>
        </ul>
    </div>
</template>
