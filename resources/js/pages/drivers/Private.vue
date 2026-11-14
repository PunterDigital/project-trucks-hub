<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import DriverAvatar from '@/components/hub/DriverAvatar.vue';

defineProps<{
    driver: { name: string; username: string | null; avatar: string | null };
}>();
</script>

<template>
    <Head :title="driver.name" />

    <div class="flex flex-col items-center gap-3 p-10 text-center">
        <DriverAvatar
            :name="driver.name"
            :avatar="driver.avatar"
            class="size-16"
        />
        <h1 class="text-xl font-semibold">{{ driver.name }}</h1>
        <p class="flex items-center gap-1.5 text-sm text-muted-foreground">
            <Lock class="size-4" /> This driver's profile is private.
        </p>
    </div>
</template>
