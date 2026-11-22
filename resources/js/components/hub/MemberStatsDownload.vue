<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import { memberStats } from '@/routes/companies';

// Member stats CSV (#5226) for owners and managers: a month or all time.
const props = defineProps<{
    slug: string;
    periods: { value: string; label: string }[];
}>();

const period = ref(props.periods[0]?.value ?? 'all');
const href = computed(
    () => memberStats(props.slug, { query: { period: period.value } }).url,
);
</script>

<template>
    <div
        class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3 text-xs"
        data-test="member-stats"
    >
        <label for="member-stats-period" class="text-muted-foreground"
            >Member stats</label
        >
        <select
            id="member-stats-period"
            v-model="period"
            class="rounded-md border bg-transparent px-1 py-0.5"
        >
            <option v-for="p in periods" :key="p.value" :value="p.value">
                {{ p.label }}
            </option>
        </select>
        <a
            :href="href"
            download
            class="inline-flex items-center gap-1 font-medium hover:underline"
            ><Download class="size-3.5" aria-hidden="true" />Download CSV</a
        >
    </div>
</template>
