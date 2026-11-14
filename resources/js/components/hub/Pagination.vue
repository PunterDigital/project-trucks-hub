<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';
import type { PaginationLink } from '@/types/hub';

defineProps<{
    links: PaginationLink[];
    lastPage: number;
}>();

function label(raw: string): string {
    return raw.replace('&laquo;', '‹').replace('&raquo;', '›');
}
</script>

<template>
    <nav
        v-if="lastPage > 1"
        class="flex flex-wrap justify-center gap-1"
        aria-label="Pagination"
    >
        <template v-for="(link, i) in links" :key="i">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                :class="
                    cn(
                        'rounded-md border px-3 py-1 text-sm',
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted',
                    )
                "
                :aria-current="link.active ? 'page' : undefined"
                >{{ label(link.label) }}</Link
            >
            <span
                v-else
                class="rounded-md px-3 py-1 text-sm text-muted-foreground"
                >{{ label(link.label) }}</span
            >
        </template>
    </nav>
</template>
