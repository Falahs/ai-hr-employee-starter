<script setup lang="ts">
import PermissionChecklist from '@/components/admin/PermissionChecklist.vue';
import SettingField from '@/components/admin/SettingField.vue';
import SettingToggle from '@/components/admin/SettingToggle.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { translate as t } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, KeyRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_active: boolean;
    must_change_password: boolean;
    last_login_at: string | null;
    permissions: string[];
}

const props = defineProps<{ member: Member; is_self: boolean; catalog: { value: string }[] }>();

const page = usePage<SharedData & { flash: { temporary_password?: string | null } }>();
const temporaryPassword = computed(() => page.props.flash?.temporary_password ?? null);

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('ui.admin.team.title'), href: '/admin/team' },
    { title: props.member.name, href: `/admin/team/${props.member.id}` },
];

const form = useForm({
    name: props.member.name,
    email: props.member.email,
    is_admin: props.member.is_admin,
    is_active: props.member.is_active,
    permissions: [...props.member.permissions],
});
const err = (k: string) => (form.errors as Record<string, string>)[k];

// SettingToggle speaks '1'/'0'
const flag = (key: 'is_admin' | 'is_active') =>
    computed({ get: () => (form[key] ? '1' : '0'), set: (v: string) => (form[key] = v === '1') });
const adminFlag = flag('is_admin');
const activeFlag = flag('is_active');

const save = () => form.put(route('admin.team.update', { member: props.member.id }), { preserveScroll: true });

const resetPassword = () => {
    if (confirm(t('ui.admin.team.reset_confirm', { name: props.member.name })))
        router.post(route('admin.team.reset-password', { member: props.member.id }), {}, { preserveScroll: true });
};

const copied = ref(false);
const copy = async () => {
    if (!temporaryPassword.value) return;
    await navigator.clipboard.writeText(temporaryPassword.value);
    copied.value = true;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="member.name" />

        <form class="max-w-4xl space-y-10 px-4 py-6" @submit.prevent="save">
            <Heading :title="member.name" :description="$t('ui.admin.team.edit_description')" />

            <!-- one-time temporary password -->
            <div v-if="temporaryPassword" class="space-y-2 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4">
                <p class="flex items-center gap-2 font-medium"><KeyRound class="size-4" />{{ $t('ui.admin.team.temp_password_title') }}</p>
                <div class="flex flex-wrap items-center gap-3">
                    <code dir="ltr" class="rounded bg-background px-3 py-1.5 font-mono text-base tracking-wider">{{ temporaryPassword }}</code>
                    <Button type="button" size="sm" variant="outline" @click="copy"><Copy class="size-4" />{{ copied ? $t('ui.common.copied') : $t('ui.common.copy') }}</Button>
                </div>
                <p class="text-xs text-muted-foreground">{{ $t('ui.admin.team.temp_password_hint') }}</p>
            </div>

            <section class="space-y-4">
                <HeadingSmall :title="$t('ui.admin.team.section.account')" />
                <div class="grid gap-4 md:grid-cols-2">
                    <SettingField id="name" :label="$t('ui.settings.profile.name')" v-model="form.name" :error="err('name')" />
                    <SettingField id="email" :label="$t('ui.auth.email')" v-model="form.email" type="email" ltr :error="err('email')" />
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <SettingToggle id="is_active" :label="$t('ui.admin.team.active')" :hint="$t('ui.admin.team.active_hint')" v-model="activeFlag" />
                    <SettingToggle id="is_admin" :label="$t('ui.admin.team.admin')" :hint="$t('ui.admin.team.admin_hint')" v-model="adminFlag" />
                </div>
                <InputError :message="err('is_admin')" />
                <div v-if="!is_self" class="flex flex-wrap items-center gap-3">
                    <Button type="button" variant="outline" size="sm" @click="resetPassword"><KeyRound class="size-4" />{{ $t('ui.admin.team.reset_password') }}</Button>
                    <span v-if="member.must_change_password" class="text-xs text-amber-700">{{ $t('ui.admin.team.temp_password_pending') }}</span>
                </div>
            </section>

            <section v-if="!form.is_admin" class="space-y-4">
                <HeadingSmall :title="$t('ui.admin.team.permissions')" :description="$t('ui.admin.team.permissions_description')" />
                <PermissionChecklist :catalog="catalog" v-model="form.permissions" />
                <InputError :message="err('permissions')" />
            </section>
            <p v-else class="rounded-md border border-dashed p-3 text-sm text-muted-foreground">{{ $t('ui.admin.team.admin_has_all') }}</p>

            <div class="flex items-center gap-3 border-t pt-6">
                <Button :disabled="form.processing">{{ $t('ui.common.save') }}</Button>
                <span v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">{{ $t('ui.common.saved') }}</span>
                <span v-if="form.hasErrors" class="text-sm text-red-600">{{ $t('ui.common.fix_errors') }}</span>
            </div>
        </form>
    </AppLayout>
</template>
