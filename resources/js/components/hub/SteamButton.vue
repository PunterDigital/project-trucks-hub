<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { redirect } from '@/routes/steam';

defineProps<{ label: string }>();

const page = usePage();

// Valve asks sites that use Steam's OpenID to link with one of its own buttons
// (https://steamcommunity.com/dev): "Sign in through Steam", served by Steam's CDN.
const valveButton =
    'https://community.steamstatic.com/public/images/signinthroughsteam/sits_01.png';
</script>

<template>
    <div v-if="page.props.steamEnabled" class="mb-6 flex flex-col gap-6">
        <!-- A plain link: Steam's OpenID page can't load inside an Inertia visit. -->
        <a
            :href="redirect().url"
            class="mx-auto rounded-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            data-test="steam-login"
            :aria-label="label"
        >
            <img
                :src="valveButton"
                :alt="label"
                width="180"
                height="35"
                referrerpolicy="no-referrer"
            />
        </a>
        <div class="flex items-center gap-3 text-xs text-muted-foreground">
            <span class="h-px flex-1 bg-border" />or<span
                class="h-px flex-1 bg-border"
            />
        </div>
    </div>
</template>
