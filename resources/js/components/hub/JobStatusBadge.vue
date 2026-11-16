<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { JobReview, JobStatus } from '@/types/hub';

defineProps<{
    status: JobStatus;
    review?: JobReview;
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
            v-if="review === 'pending'"
            variant="outline"
            class="border-amber-500/50 text-amber-700 dark:text-amber-400"
            title="Flagged by ProjectTrucks or the Hub's checks: only you see it until staff have reviewed it"
            data-test="review-pending"
            >Pending review</Badge
        >
        <Badge
            v-else-if="review === 'rejected'"
            variant="outline"
            class="border-red-500/50 text-red-700 dark:text-red-400"
            title="Rejected by Hub staff: only you see it, and it doesn't count towards stats"
            data-test="review-rejected"
            >Rejected</Badge
        >
        <Badge v-if="hidden" variant="outline" class="text-muted-foreground"
            >Hidden</Badge
        >
    </span>
</template>
