<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Moon, Settings, ShieldCheck, Sun } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useAppearance } from '@/composables/useAppearance';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();

// Light / Dark from the menu (#4658): one click away from every page. Settings → Appearance
// has the third choice (System). The menu stays open so the change is seen at once.
const { resolvedAppearance, updateAppearance } = useAppearance();
const dark = computed(() => resolvedAppearance.value === 'dark');
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
        <!-- The admin panel is Filament, not Inertia: a plain link loads it as a full page. -->
        <DropdownMenuItem v-if="user.is_staff" :as-child="true">
            <a
                class="block w-full cursor-pointer"
                href="/admin"
                data-test="admin-link"
            >
                <ShieldCheck class="mr-2 h-4 w-4" />
                Admin
            </a>
        </DropdownMenuItem>
        <DropdownMenuItem
            class="cursor-pointer"
            data-test="theme-toggle"
            :data-theme="dark ? 'dark' : 'light'"
            @select.prevent="updateAppearance(dark ? 'light' : 'dark')"
        >
            <component :is="dark ? Sun : Moon" class="mr-2 h-4 w-4" />
            {{ dark ? 'Light mode' : 'Dark mode' }}
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
