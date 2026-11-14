<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { JobStatus } from '@/types/hub';

defineProps<{
    status: JobStatus;
    flagged?: boolean;
    hidden?: boolean;
}>();

const labels: Record<JobStatus, string> = {
    DELIVERED: 'Delivered',
    LATE: 'Late',
    CANCELLED: 'Cancelled',
};
</script>

<template>
    <span class="inline-flex flex-wrap gap-1">
        <Badge
            :variant="status === 'DELIVERED' ? 'secondary' : 'outline'"
            :class="{
                'border-amber-500/50 text-amber-700 dark:text-amber-400':
                    status === 'LATE',
                'text-muted-foreground': status === 'CANCELLED',
            }"
            >{{ labels[status] }}</Badge
        >
        <Badge
            v-if="flagged"
            variant="outline"
            class="border-red-500/50 text-red-700 dark:text-red-400"
            title="Flagged as implausible (by ProjectTrucks or the Hub's checks); it doesn't count towards stats"
            >Flagged</Badge
        >
        <Badge v-if="hidden" variant="outline" class="text-muted-foreground"
            >Hidden</Badge
        >
    </span>
</template>
