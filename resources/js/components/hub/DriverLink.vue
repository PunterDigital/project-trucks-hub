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
            <div
                v-if="driver.username"
                class="flex min-w-0 items-center gap-1.5"
            >
                <Link
                    :href="showDriver(driver.username)"
                    class="truncate font-medium hover:underline"
                    >{{ driver.name }}</Link
                >
                <span
                    v-if="driver.level"
                    :title="`Level ${driver.level} · ${driver.rank}`"
                    class="shrink-0 rounded bg-muted px-1 text-[0.65rem] font-semibold text-muted-foreground tabular-nums"
                    data-test="driver-level-badge"
                    >Lv {{ driver.level }}</span
                >
            </div>
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
