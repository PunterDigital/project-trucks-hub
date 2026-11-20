<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    CalendarDays,
    Download,
    Home,
    LayoutGrid,
    Map as MapIcon,
    Newspaper,
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
import {
    dashboard,
    download,
    home,
    leaderboards,
    map,
    privacy,
    terms,
} from '@/routes';
import { index as blog } from '@/routes/blog';
import { index as companies } from '@/routes/companies';
import { index as drivers } from '@/routes/drivers';
import { index as events } from '@/routes/events';
import { index as guides } from '@/routes/guides';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Home', href: home(), icon: Home },
    ...(page.props.auth.user
        ? [{ title: 'My dashboard', href: dashboard(), icon: LayoutGrid }]
        : []),
    { title: 'Drivers', href: drivers(), icon: Users },
    { title: 'Companies', href: companies(), icon: Building2 },
    { title: 'Events', href: events(), icon: CalendarDays },
    { title: 'Leaderboards', href: leaderboards(), icon: Trophy },
    { title: 'Live map', href: map(), icon: MapIcon },
    { title: 'Blog', href: blog(), icon: Newspaper },
    { title: 'Guides', href: guides(), icon: BookOpen },
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
            <nav
                class="flex gap-3 px-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden"
                aria-label="Site"
                data-test="legal-links"
            >
                <Link :href="guides()" class="hover:underline">Guides</Link>
                <Link :href="privacy()" class="hover:underline">Privacy</Link>
                <Link :href="terms()" class="hover:underline">Terms</Link>
            </nav>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
