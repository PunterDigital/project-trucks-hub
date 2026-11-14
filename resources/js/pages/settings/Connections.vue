<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import SteamIcon from '@/components/hub/SteamIcon.vue';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/connections';
import { destroy, redirect } from '@/routes/steam';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Connections', href: edit() }] },
});

defineProps<{
    steamEnabled: boolean;
    steam: { id: string; profile_url: string } | null;
}>();

function unlink() {
    if (
        confirm(
            'Unlink your Steam account? You will sign in with your email and password.',
        )
    ) {
        router.delete(destroy().url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Connections" />

    <h1 class="sr-only">Connections</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Steam"
            description="Sign in with Steam and show your Steam profile on your driver page"
        />

        <div class="flex items-center gap-3 rounded-xl border p-4">
            <SteamIcon class="size-6" />
            <div class="flex-1 text-sm">
                <template v-if="steam">
                    <p class="font-medium">Linked</p>
                    <a
                        :href="steam.profile_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-muted-foreground hover:underline"
                        >Steam profile <ExternalLink class="size-3"
                    /></a>
                </template>
                <p v-else class="text-muted-foreground">Not linked.</p>
            </div>
            <Button v-if="steam" variant="outline" size="sm" @click="unlink"
                >Unlink</Button
            >
            <!-- Valve's own "Sign in through Steam" button, as Valve asks OpenID sites to use. -->
            <a
                v-else-if="steamEnabled"
                :href="redirect().url"
                aria-label="Link Steam: sign in through Steam"
                data-test="steam-link"
            >
                <img
                    src="https://community.steamstatic.com/public/images/signinthroughsteam/sits_01.png"
                    alt="Sign in through Steam"
                    width="180"
                    height="35"
                    referrerpolicy="no-referrer"
                />
            </a>
            <span v-else class="text-xs text-muted-foreground"
                >Steam sign-in is switched off on this server.</span
            >
        </div>
    </div>
</template>
