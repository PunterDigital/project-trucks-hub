<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Recruitment } from '@/types/hub';

defineProps<{ recruitment: Recruitment }>();

const labels: Record<Recruitment, string> = {
    open: 'Open',
    apply: 'Recruiting',
    closed: 'Closed',
};
</script>

<template>
    <Badge
        :variant="recruitment === 'closed' ? 'outline' : 'secondary'"
        :class="{
            'text-green-700 dark:text-green-400': recruitment === 'open',
            'text-muted-foreground': recruitment === 'closed',
        }"
        >{{ labels[recruitment] }}</Badge
    >
</template>
