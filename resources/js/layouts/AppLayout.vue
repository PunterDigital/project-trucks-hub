<script setup lang="ts">
import { useAutoRefresh } from '@/composables/useAutoRefresh';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

// Fresh data every minute while the tab is visible (#4723). The layout stays mounted
// across visits, so there's one timer for the whole session.
useAutoRefresh();

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
