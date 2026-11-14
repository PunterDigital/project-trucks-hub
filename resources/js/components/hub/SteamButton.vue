<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import SteamIcon from '@/components/hub/SteamIcon.vue';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/steam';

defineProps<{ label: string }>();

const page = usePage();
</script>

<template>
    <div v-if="page.props.steamEnabled" class="mb-6 flex flex-col gap-6">
        <!-- A plain link: Steam's OpenID page can't load inside an Inertia visit. -->
        <Button as-child variant="outline" class="w-full">
            <a :href="redirect().url" data-test="steam-login"
                ><SteamIcon class="size-4" /> {{ label }}</a
            >
        </Button>
        <div class="flex items-center gap-3 text-xs text-muted-foreground">
            <span class="h-px flex-1 bg-border" />or<span
                class="h-px flex-1 bg-border"
            />
        </div>
    </div>
</template>
