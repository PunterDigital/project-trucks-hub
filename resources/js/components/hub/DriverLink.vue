<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DriverAvatar from '@/components/hub/DriverAvatar.vue';
import { show as showCompany } from '@/routes/companies';
import { show as showDriver } from '@/routes/drivers';
import type { DriverSummary } from '@/types/hub';

withDefaults(
    defineProps<{
        driver: DriverSummary;
        showCompany?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { showCompany: true, size: 'sm' },
);
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <DriverAvatar
            :name="driver.name"
            :avatar="driver.avatar"
            :class="size === 'md' ? 'size-10' : 'size-7'"
        />
        <div class="min-w-0">
            <Link
                v-if="driver.username"
                :href="showDriver(driver.username)"
                class="block truncate font-medium hover:underline"
                >{{ driver.name }}</Link
            >
            <span v-else class="block truncate text-muted-foreground">{{
                driver.name
            }}</span>
            <Link
                v-if="showCompany && driver.company"
                :href="showCompany(driver.company.slug)"
                class="flex items-center gap-1 truncate text-xs text-muted-foreground hover:underline"
                ><img
                    v-if="driver.company.logo"
                    :src="driver.company.logo"
                    alt=""
                    class="size-3.5 shrink-0 rounded-sm object-cover"
                    loading="lazy"
                /><span class="truncate"
                    >[{{ driver.company.tag }}] {{ driver.company.name }}</span
                ></Link
            >
        </div>
    </div>
</template>
