<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown, LogIn } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { login, register } from '@/routes';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { isMobile, state } = useSidebar();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem v-if="user">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="sidebar-menu-button"
                    >
                        <UserInfo :user="user" />
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
        <SidebarMenuItem v-else>
            <SidebarMenuButton
                v-if="state === 'collapsed' && !isMobile"
                as-child
                tooltip="Log in"
            >
                <Link :href="login()"><LogIn /><span>Log in</span></Link>
            </SidebarMenuButton>
            <div v-else class="grid gap-2 p-1">
                <Button as-child size="sm">
                    <Link :href="register()">Sign up</Link>
                </Button>
                <Button as-child size="sm" variant="outline">
                    <Link :href="login()">Log in</Link>
                </Button>
            </div>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
