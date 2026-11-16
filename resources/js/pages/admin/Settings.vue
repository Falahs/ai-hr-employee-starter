<script setup lang="ts">
import SettingField from '@/components/admin/SettingField.vue';
import SettingToggle from '@/components/admin/SettingToggle.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { translate as t } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { postJson } from '@/lib/http';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Loader2, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

/** The mail section's status: what waits to go out, what failed, and the mailbox's last read. */
interface MailStatus {
    queued: number;
    failed: number;
    last_sent_at: string | null;
    fetch: { at: string; ok: boolean; imported: number; skipped: number; error: string | null; baseline: boolean } | null;
    configured: boolean;
}

const props = defineProps<{
    section: string;
    sections: string[];
    values: Record<string, string>;
    secrets: Record<string, string | null>;
    status: MailStatus | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('ui.admin.settings.title'), href: '/admin/settings' }];

// one form per section; keys on the wire are setting keys with __ for dots
const form = useForm({ values: { ...props.values } });
const v = form.values as Record<string, string>;
const err = (k: string) => (form.errors as Record<string, string>)[k];
const field = (k: string) => ({ id: k, label: t('ui.admin.settings.field.' + k.replaceAll('__', '.')), error: err(k) });

const save = () => form.put(route('admin.settings.update', { section: props.section }), { preserveScroll: true, onSuccess: () => clearSecrets() });
const clearSecrets = () => {
    for (const k of Object.keys(props.secrets)) if (k in v) v[k] = '';
};

const secretPlaceholder = (k: string) => (props.secrets[k] ? t('ui.admin.settings.secret_saved', { tail: props.secrets[k]! }) : t('ui.admin.settings.secret_empty'));

// connection tests: nothing is saved by a test
type AiResult = { model: string; ok: boolean; ms: number; error: string | null };
type MailResult = { imap: { ok: boolean; error: string | null }; smtp: { ok: boolean; error: string | null } };
const testing = ref(false);
const aiResults = ref<AiResult[] | null>(null);
const mailResult = ref<MailResult | null>(null);
const testError = ref<string | null>(null);

const runTest = async (kind: 'ai' | 'mail') => {
    testing.value = true;
    testError.value = null;
    aiResults.value = null;
    mailResult.value = null;
    try {
        const res = await postJson<{ results?: AiResult[]; message?: string } & Partial<MailResult>>(
            route(kind === 'ai' ? 'admin.settings.ai.test' : 'admin.settings.mail.test'),
            { values: v },
        );
        if (!res.ok) {
            testError.value = res.data.message ?? t('ui.admin.settings.test.failed_request');
        } else if (kind === 'ai') {
            aiResults.value = res.data.results ?? [];
        } else {
            mailResult.value = res.data as MailResult;
        }
    } finally {
        testing.value = false;
    }
};

const fmt = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="$t('ui.admin.settings.title')" />

        <div class="px-4 py-6">
            <Heading :title="$t('ui.admin.settings.title')" :description="$t('ui.admin.settings.description')" />

            <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
                <aside class="w-full lg:w-52">
                    <nav class="flex flex-row flex-wrap gap-1 lg:flex-col">
                        <Button v-for="s in sections" :key="s" variant="ghost" :class="['justify-start', { 'bg-muted': s === section }]" as-child>
                            <Link :href="route('admin.settings', { section: s })">{{ $t('ui.admin.settings.section.' + s) }}</Link>
                        </Button>
                    </nav>
                </aside>

                <div class="min-w-0 flex-1">
                    <!-- AI provider and the assistant's instructions -->
                    <form v-if="section === 'ai'" class="max-w-2xl space-y-6" @submit.prevent="save">
                        <HeadingSmall :title="$t('ui.admin.settings.section.ai')" :description="$t('ui.admin.settings.ai.description')" />
                        <SettingField v-bind="field('ai__base_url')" v-model="v.ai__base_url" ltr />
                        <SettingField
                            v-bind="field('ai__api_key')"
                            v-model="v.ai__api_key"
                            type="password"
                            ltr
                            autocomplete="new-password"
                            :placeholder="secretPlaceholder('ai__api_key')"
                            :hint="$t('ui.admin.settings.secret_hint')"
                        />
                        <SettingField v-bind="field('ai__model')" v-model="v.ai__model" ltr />
                        <div class="grid gap-6 md:grid-cols-2">
                            <SettingField v-bind="field('ai__max_tokens')" v-model="v.ai__max_tokens" type="number" ltr :hint="$t('ui.admin.settings.ai.max_tokens_hint')" />
                            <SettingField v-bind="field('ai__timeout_seconds')" v-model="v.ai__timeout_seconds" type="number" ltr />
                        </div>

                        <div class="grid gap-2">
                            <Label for="ai__instructions">{{ $t('ui.admin.settings.field.ai.instructions') }}</Label>
                            <textarea
                                id="ai__instructions"
                                v-model="v.ai__instructions"
                                dir="auto"
                                rows="8"
                                class="w-full rounded-md border border-input bg-background p-3 text-sm leading-7 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                            <p class="text-xs text-muted-foreground">{{ $t('ui.admin.settings.ai.instructions_hint') }}</p>
                            <InputError :message="err('ai__instructions')" />
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <Button :disabled="form.processing">{{ $t('ui.common.save') }}</Button>
                            <Button type="button" variant="outline" :disabled="testing" @click="runTest('ai')">
                                <Loader2 v-if="testing" class="size-4 animate-spin" />{{ $t('ui.admin.settings.test.button') }}
                            </Button>
                            <span v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">{{ $t('ui.common.saved') }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ $t('ui.admin.settings.test.hint') }}</p>
                        <InputError :message="testError ?? undefined" />
                        <ul v-if="aiResults" class="space-y-2 rounded-md border p-4 text-sm">
                            <li v-for="r in aiResults" :key="r.model" class="flex items-start gap-2">
                                <CheckCircle2 v-if="r.ok" class="mt-0.5 size-4 shrink-0 text-green-600" />
                                <XCircle v-else class="mt-0.5 size-4 shrink-0 text-red-600" />
                                <span dir="ltr" class="font-mono">{{ r.model }}</span>
                                <span class="text-muted-foreground">{{ r.ok ? $t('ui.admin.settings.test.ok_ms', { ms: r.ms }) : r.error }}</span>
                            </li>
                        </ul>
                    </form>

                    <!-- Mailbox -->
                    <form v-else-if="section === 'mail'" class="max-w-2xl space-y-6" @submit.prevent="save">
                        <HeadingSmall :title="$t('ui.admin.settings.section.mail')" :description="$t('ui.admin.settings.mail.description')" />
                        <div class="grid gap-6 md:grid-cols-2">
                            <SettingField v-bind="field('mail__address')" v-model="v.mail__address" type="email" ltr placeholder="hr@example.com" />
                            <SettingField v-bind="field('mail__from_name')" v-model="v.mail__from_name" />
                        </div>
                        <SettingField
                            v-bind="field('mail__app_password')"
                            v-model="v.mail__app_password"
                            type="password"
                            ltr
                            autocomplete="new-password"
                            :placeholder="secretPlaceholder('mail__app_password')"
                            :hint="$t('ui.admin.settings.mail.app_password_hint')"
                        />
                        <div class="grid gap-6 md:grid-cols-4">
                            <div class="md:col-span-3"><SettingField v-bind="field('mail__imap_host')" v-model="v.mail__imap_host" ltr /></div>
                            <SettingField v-bind="field('mail__imap_port')" v-model="v.mail__imap_port" type="number" ltr />
                            <div class="md:col-span-3"><SettingField v-bind="field('mail__smtp_host')" v-model="v.mail__smtp_host" ltr /></div>
                            <SettingField v-bind="field('mail__smtp_port')" v-model="v.mail__smtp_port" type="number" ltr />
                        </div>

                        <!-- each direction is turned on on purpose -->
                        <HeadingSmall :title="$t('ui.admin.settings.mail.connect_title')" :description="$t('ui.admin.settings.mail.connect_description')" />
                        <div class="grid gap-3">
                            <SettingToggle
                                id="mail__receiving_enabled"
                                :label="$t('ui.admin.settings.field.mail.receiving_enabled')"
                                :hint="$t('ui.admin.settings.mail.receiving_hint')"
                                v-model="v.mail__receiving_enabled"
                            />
                            <SettingToggle
                                id="mail__sending_enabled"
                                :label="$t('ui.admin.settings.field.mail.sending_enabled')"
                                :hint="$t('ui.admin.settings.mail.sending_hint')"
                                v-model="v.mail__sending_enabled"
                            />
                            <p
                                v-if="status && status.queued && v.mail__sending_enabled === '1' && values.mail__sending_enabled !== '1'"
                                class="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                            >
                                {{ $t('ui.admin.settings.mail.backlog_warning', { n: status.queued }) }}
                            </p>
                            <div v-if="status" class="space-y-1 rounded-md border p-3 text-sm text-muted-foreground">
                                <p v-if="!status.configured">{{ $t('ui.admin.settings.mail.status_not_configured') }}</p>
                                <p>{{ $t('ui.admin.settings.mail.status_queue', { queued: status.queued, failed: status.failed }) }}</p>
                                <p v-if="status.last_sent_at">{{ $t('ui.admin.settings.mail.status_last_sent', { at: fmt(status.last_sent_at) }) }}</p>
                                <template v-if="status.fetch">
                                    <p v-if="status.fetch.baseline">{{ $t('ui.admin.settings.mail.status_baseline', { at: fmt(status.fetch.at) }) }}</p>
                                    <p v-else-if="status.fetch.ok">
                                        {{ $t('ui.admin.settings.mail.status_fetch_ok', { at: fmt(status.fetch.at), imported: status.fetch.imported, skipped: status.fetch.skipped }) }}
                                    </p>
                                    <p v-else class="text-red-600">
                                        {{ $t('ui.admin.settings.mail.status_fetch_error', { at: fmt(status.fetch.at) }) }}
                                        <span dir="ltr" class="block text-xs">{{ status.fetch.error }}</span>
                                    </p>
                                </template>
                                <p v-else-if="values.mail__receiving_enabled === '1'">{{ $t('ui.admin.settings.mail.status_fetch_waiting') }}</p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <Button :disabled="form.processing">{{ $t('ui.common.save') }}</Button>
                            <Button type="button" variant="outline" :disabled="testing" @click="runTest('mail')">
                                <Loader2 v-if="testing" class="size-4 animate-spin" />{{ $t('ui.admin.settings.test.button') }}
                            </Button>
                            <span v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">{{ $t('ui.common.saved') }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ $t('ui.admin.settings.test.hint') }}</p>
                        <InputError :message="testError ?? undefined" />
                        <ul v-if="mailResult" class="space-y-2 rounded-md border p-4 text-sm">
                            <li v-for="kind in ['imap', 'smtp'] as const" :key="kind" class="flex items-start gap-2">
                                <CheckCircle2 v-if="mailResult[kind].ok" class="mt-0.5 size-4 shrink-0 text-green-600" />
                                <XCircle v-else class="mt-0.5 size-4 shrink-0 text-red-600" />
                                <span>{{ $t('ui.admin.settings.mail.test_' + kind) }}</span>
                                <span v-if="!mailResult[kind].ok" dir="ltr" class="text-muted-foreground">{{ mailResult[kind].error }}</span>
                            </li>
                        </ul>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
