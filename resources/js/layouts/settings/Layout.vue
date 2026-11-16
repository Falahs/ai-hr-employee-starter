<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { translate as t } from '@/i18n';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';

const sidebarNavItems: NavItem[] = [
    {
        title: t('ui.settings.nav.profile'),
        href: '/settings/profile',
    },
    {
        title: t('ui.settings.nav.password'),
        href: '/settings/password',
    },
    {
        title: t('ui.settings.nav.appearance'),
        href: '/settings/appearance',
    },
];

const currentPath = window.location.pathname;
</script>

<template>
    <div class="px-4 py-6">
        <Heading :title="$t('ui.settings.title')" :description="$t('ui.settings.description')" />

        <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col gap-1">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 md:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
