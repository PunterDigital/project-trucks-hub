<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { timeAgo } from '@/lib/format';
import { open, readAll } from '@/routes/notifications';
import { edit as notificationSettings } from '@/routes/notification-settings';
import type { HubNotifications } from '@/types/hub';

// The header bell (#4643). Its data is a shared prop, refreshed with every visit and by the
// layout's 60 s auto-refresh.
const page = usePage();
const notifications = computed(
    () => page.props.notifications as HubNotifications | null,
);
const unreadLabel = computed(() => {
    const n = notifications.value?.unread ?? 0;

    return n > 9 ? '9+' : String(n);
});

function openItem(id: string) {
    router.post(open(id).url);
}

function markAllRead() {
    router.post(
        readAll().url,
        {},
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <DropdownMenu v-if="notifications">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="
                    notifications.unread > 0
                        ? `Notifications, ${notifications.unread} unread`
                        : 'Notifications'
                "
                data-test="notification-bell"
            >
                <Bell class="size-5" />
                <span
                    v-if="notifications.unread > 0"
                    class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] leading-none font-semibold text-white"
                    data-test="notification-count"
                    aria-hidden="true"
                    >{{ unreadLabel }}</span
                >
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80" :side-offset="4">
            <div class="flex items-center justify-between gap-2 pr-1">
                <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                <Button
                    v-if="notifications.unread > 0"
                    variant="ghost"
                    size="sm"
                    class="h-7 text-xs"
                    @click="markAllRead"
                    >Mark all read</Button
                >
            </div>
            <DropdownMenuSeparator />
            <p
                v-if="notifications.items.length === 0"
                class="px-2 py-6 text-center text-sm text-muted-foreground"
            >
                Nothing yet. Company news and new linked apps show up here.
            </p>
            <div v-else class="max-h-96 overflow-y-auto">
                <DropdownMenuItem
                    v-for="item in notifications.items"
                    :key="item.id"
                    class="flex cursor-pointer items-start gap-2 py-2"
                    :data-read="item.read ? 'true' : 'false'"
                    data-test="notification-item"
                    @select="openItem(item.id)"
                >
                    <span
                        class="mt-1.5 size-2 shrink-0 rounded-full"
                        :class="item.read ? 'bg-transparent' : 'bg-red-600'"
                        aria-hidden="true"
                    />
                    <span class="flex-1">
                        <span
                            class="block text-sm"
                            :class="{ 'font-medium': !item.read }"
                            >{{ item.message }}</span
                        >
                        <span class="block text-xs text-muted-foreground">{{
                            timeAgo(item.at)
                        }}</span>
                    </span>
                    <span v-if="!item.read" class="sr-only">(unread)</span>
                </DropdownMenuItem>
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link
                    :href="notificationSettings()"
                    class="w-full cursor-pointer text-xs text-muted-foreground"
                    >Email settings</Link
                >
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
