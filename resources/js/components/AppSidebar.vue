<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useI18n } from '@/i18n';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, Mail, MessagesSquare, SlidersHorizontal, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const { t, dir } = useI18n();
const page = usePage<SharedData>();

// Each item appears only with the permission its page checks on the server.
const can = (p: string) => page.props.auth.user?.permissions?.includes(p) ?? false;
const mainNavItems = computed<NavItem[]>(() => [
    { title: t('ui.nav.dashboard'), href: '/dashboard', icon: LayoutGrid },
    ...(can('email.view') ? [{ title: t('ui.nav.mail'), href: '/mail', icon: Mail }] : []),
    ...(can('chat.use') ? [{ title: t('ui.nav.chat'), href: '/chat', icon: MessagesSquare }] : []),
]);

// Shown only to the admin; the routes behind them are admin-only on the server as well.
const adminNavItems = computed<NavItem[]>(() =>
    page.props.auth.user?.is_admin
        ? [
              { title: t('ui.nav.team'), href: '/admin/team', icon: Users },
              { title: t('ui.nav.system_settings'), href: '/admin/settings', icon: SlidersHorizontal },
          ]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" :side="dir() === 'rtl' ? 'right' : 'left'">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" :label="$t('ui.nav.group.main')" />
            <NavMain v-if="adminNavItems.length" :items="adminNavItems" :label="$t('ui.nav.group.admin')" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
