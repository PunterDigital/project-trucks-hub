<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { MonitorSmartphone } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import { destroy, index } from '@/routes/devices';
import { show as link } from '@/routes/link';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Linked apps', href: index() }] },
});

defineProps<{
    devices: {
        id: number;
        name: string;
        created_at: string | null;
        last_used_at: string | null;
    }[];
    jobsSynced: number;
}>();

function unlink(id: number, name: string) {
    if (
        confirm(
            `Unlink ${name}? It stops syncing. Jobs already synced stay on your profile.`,
        )
    ) {
        router.delete(destroy(id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Linked apps" />

    <h1 class="sr-only">Linked apps</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Linked apps"
            :description="`ProjectTrucks installs that sync to your account. ${jobsSynced} jobs synced so far.`"
        />

        <p v-if="devices.length === 0" class="text-sm text-muted-foreground">
            Nothing linked yet. In ProjectTrucks open
            <strong>Settings → Hub</strong> and click
            <strong>Link account</strong>, or enter its code on the
            <Link :href="link()" class="underline">link page</Link>.
        </p>

        <ul v-else class="divide-y rounded-xl border" data-test="devices">
            <li
                v-for="device in devices"
                :key="device.id"
                class="flex items-center gap-3 p-4"
            >
                <MonitorSmartphone class="size-5 text-muted-foreground" />
                <div class="flex-1">
                    <p class="text-sm font-medium">{{ device.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        Linked {{ timeAgo(device.created_at) }} · last sync
                        {{ timeAgo(device.last_used_at) }}
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="unlink(device.id, device.name)"
                    >Unlink</Button
                >
            </li>
        </ul>
    </div>
</template>
