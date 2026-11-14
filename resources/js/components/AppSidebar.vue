<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Download,
    Home,
    LayoutGrid,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, download, home, leaderboards } from '@/routes';
import { index as companies } from '@/routes/companies';
import { index as drivers } from '@/routes/drivers';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Home', href: home(), icon: Home },
    ...(page.props.auth.user
        ? [{ title: 'My dashboard', href: dashboard(), icon: LayoutGrid }]
        : []),
    { title: 'Drivers', href: drivers(), icon: Users },
    { title: 'Companies', href: companies(), icon: Building2 },
    { title: 'Leaderboards', href: leaderboards(), icon: Trophy },
    { title: 'Get ProjectTrucks', href: download(), icon: Download },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
