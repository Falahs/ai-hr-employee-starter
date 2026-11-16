<script setup lang="ts">
import SettingField from '@/components/admin/SettingField.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { translate as t } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ sending: boolean }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('ui.nav.mail'), href: '/mail' },
    { title: t('ui.mail.compose'), href: '/mail/compose' },
];

const form = useForm({ to: '', subject: '', body: '' });
const send = () => form.post(route('mail.send'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="$t('ui.mail.compose')" />

        <form class="max-w-3xl space-y-6 px-4 py-6" @submit.prevent="send">
            <Heading :title="$t('ui.mail.compose')" :description="$t('ui.mail.queue_hint')" />

            <p v-if="!sending" class="rounded-md border border-dashed p-3 text-sm text-muted-foreground">{{ $t('ui.mail.sending_off') }}</p>

            <SettingField id="to" :label="$t('ui.mail.field.to')" v-model="form.to" type="email" ltr :error="form.errors.to" />
            <SettingField id="subject" :label="$t('ui.mail.field.subject')" v-model="form.subject" :error="form.errors.subject" />
            <div class="grid gap-2">
                <Label for="body">{{ $t('ui.mail.field.body') }}</Label>
                <textarea
                    id="body"
                    v-model="form.body"
                    rows="12"
                    dir="auto"
                    class="w-full rounded-md border border-input bg-background p-3 text-sm leading-7 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <InputError :message="form.errors.body" />
            </div>

            <div class="flex gap-3">
                <Button :disabled="form.processing">{{ $t('ui.mail.send') }}</Button>
                <Button type="button" variant="ghost" as-child><Link :href="route('mail')">{{ $t('ui.common.cancel') }}</Link></Button>
            </div>
        </form>
    </AppLayout>
</template>
