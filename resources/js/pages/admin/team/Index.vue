<script setup lang="ts">
import SettingField from '@/components/admin/SettingField.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { translate as t } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { KeyRound, Plus, ShieldCheck, UserX } from 'lucide-vue-next';
import { ref } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_active: boolean;
    permissions_count: number;
    must_change_password: boolean;
    last_login_at: string | null;
}

defineProps<{ members: Member[]; catalog: { value: string }[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('ui.admin.team.title'), href: '/admin/team' }];

const adding = ref(false);
const memberForm = useForm({ name: '', email: '' });
const addMember = () => memberForm.post(route('admin.team.store'));

const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : t('ui.admin.team.never'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="$t('ui.admin.team.title')" />

        <div class="space-y-8 px-4 py-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="$t('ui.admin.team.title')" :description="$t('ui.admin.team.description')" />
                <Button v-if="!adding" @click="adding = true"><Plus class="size-4" />{{ $t('ui.admin.team.add') }}</Button>
            </div>

            <form v-if="adding" class="max-w-3xl space-y-4 rounded-lg border p-4" @submit.prevent="addMember">
                <HeadingSmall :title="$t('ui.admin.team.add')" :description="$t('ui.admin.team.add_description')" />
                <div class="grid gap-4 md:grid-cols-2">
                    <SettingField id="new-name" :label="$t('ui.settings.profile.name')" v-model="memberForm.name" :error="memberForm.errors.name" />
                    <SettingField id="new-email" :label="$t('ui.auth.email')" v-model="memberForm.email" type="email" ltr :error="memberForm.errors.email" />
                </div>
                <div class="flex gap-3">
                    <Button :disabled="memberForm.processing">{{ $t('ui.admin.team.create') }}</Button>
                    <Button type="button" variant="ghost" @click="adding = false">{{ $t('ui.common.cancel') }}</Button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="p-3 text-start font-medium">{{ $t('ui.settings.profile.name') }}</th>
                            <th class="p-3 text-start font-medium">{{ $t('ui.admin.team.permissions') }}</th>
                            <th class="p-3 text-start font-medium">{{ $t('ui.admin.team.last_login') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in members" :key="m.id" class="border-t hover:bg-accent/30" :class="{ 'opacity-60': !m.is_active }">
                            <td class="p-3">
                                <Link :href="route('admin.team.edit', { member: m.id })" class="font-medium hover:underline">{{ m.name }}</Link>
                                <span dir="ltr" class="block text-xs text-muted-foreground">{{ m.email }}</span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    <span v-if="m.is_admin" class="inline-flex items-center gap-1 rounded bg-primary/10 px-1.5 py-0.5 text-xs">
                                        <ShieldCheck class="size-3" />{{ $t('ui.admin.team.admin') }}
                                    </span>
                                    <span v-if="!m.is_active" class="inline-flex items-center gap-1 rounded bg-red-500/10 px-1.5 py-0.5 text-xs text-red-600">
                                        <UserX class="size-3" />{{ $t('ui.admin.team.inactive') }}
                                    </span>
                                    <span v-if="m.must_change_password" class="inline-flex items-center gap-1 rounded bg-amber-500/10 px-1.5 py-0.5 text-xs text-amber-700">
                                        <KeyRound class="size-3" />{{ $t('ui.admin.team.temp_password_pending') }}
                                    </span>
                                </span>
                            </td>
                            <td class="p-3">{{ $t('ui.admin.team.permissions_count', { n: m.permissions_count, total: catalog.length }) }}</td>
                            <td class="p-3 text-muted-foreground">{{ fmt(m.last_login_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
