<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { translate as t, useI18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Download, FileText, Image, Reply, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Attachment {
    id: number;
    name: string;
    mime: string;
    size: number;
    inline: boolean;
}
interface Email {
    id: number;
    direction: 'in' | 'out';
    status: string;
    from_email: string | null;
    from_name: string | null;
    to: string[];
    subject: string | null;
    body_text: string | null;
    error: string | null;
    created_at: string;
    sent_at: string | null;
    sender: string | null;
    attachments: Attachment[];
    refused: { name: string; reason: string }[];
}

const props = defineProps<{
    email: Email;
    thread: { id: number; direction: 'in' | 'out'; status: string; from_email: string | null; from_name: string | null; subject: string | null; created_at: string }[];
    can_send: boolean;
}>();

const { locale } = useI18n();
const flash = computed(() => (usePage().props as { flash?: { status?: string | null } }).flash?.status ?? null);
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('ui.nav.mail'), href: '/mail' },
    { title: props.email.subject || t('ui.mail.no_subject'), href: `/mail/${props.email.id}` },
];
const when = (iso: string) => new Date(iso).toLocaleString(locale() === 'ar' ? 'ar-SA' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' });
const size = (b: number) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');

const replying = ref(false);
const form = useForm({ reply_to: props.email.id, body: '' });
const sendReply = () => form.post(route('mail.send'), { onSuccess: () => (replying.value = false) });
const retry = () => router.post(route('mail.retry', { email: props.email.id }), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="email.subject || $t('ui.mail.no_subject')" />

        <div class="max-w-4xl space-y-6 px-4 py-6">
            <p v-if="flash === 'queued'" class="rounded-md border border-green-600/40 bg-green-600/10 p-3 text-sm">{{ $t('ui.mail.queued_notice') }}</p>

            <header class="space-y-2">
                <h1 dir="auto" class="text-xl font-semibold">{{ email.subject || $t('ui.mail.no_subject') }}</h1>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-muted-foreground">
                    <template v-if="email.direction === 'in'">
                        <span class="font-medium text-foreground">{{ email.from_name || email.from_email }}</span>
                        <span dir="ltr">&lt;{{ email.from_email }}&gt;</span>
                    </template>
                    <template v-else>
                        <span>{{ $t('ui.mail.to', { to: email.to.join(', ') }) }}</span>
                        <span v-if="email.sender">· {{ $t('ui.mail.written_by', { name: email.sender }) }}</span>
                        <span class="rounded border px-1.5 text-xs" :class="{ 'border-red-500/50 text-red-600': email.status === 'failed' }">{{ $t('ui.mail.status.' + email.status) }}</span>
                    </template>
                    <span class="ms-auto">{{ when(email.sent_at ?? email.created_at) }}</span>
                </div>
            </header>

            <div v-if="email.status === 'failed'" class="space-y-2 rounded-md border border-red-500/40 bg-red-500/5 p-3 text-sm">
                <p>{{ $t('ui.mail.failed_notice') }}</p>
                <p v-if="email.error" dir="ltr" class="text-xs text-muted-foreground">{{ email.error }}</p>
                <Button v-if="can_send" size="sm" variant="outline" @click="retry"><RotateCcw class="size-4" />{{ $t('ui.mail.retry') }}</Button>
            </div>

            <!-- plain text only: the HTML part of an email is never rendered -->
            <article dir="auto" class="whitespace-pre-wrap rounded-lg border bg-card p-5 text-sm leading-7">{{ email.body_text || $t('ui.mail.no_body') }}</article>

            <section v-if="email.attachments.length" class="space-y-2">
                <p class="text-sm font-medium">{{ $t('ui.mail.attachments', { n: email.attachments.length }) }}</p>
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="a in email.attachments" :key="a.id" class="flex items-center gap-3 rounded-md border p-3 text-sm">
                        <Image v-if="a.mime.startsWith('image/')" class="size-5 shrink-0 text-muted-foreground" />
                        <FileText v-else class="size-5 shrink-0 text-muted-foreground" />
                        <span class="min-w-0 flex-1">
                            <a v-if="a.inline" :href="route('mail.attachment', { attachment: a.id })" target="_blank" rel="noopener" class="block truncate hover:underline" dir="auto">{{ a.name }}</a>
                            <span v-else class="block truncate" dir="auto">{{ a.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ size(a.size) }}</span>
                        </span>
                        <a :href="route('mail.attachment', { attachment: a.id, download: 1 })" :aria-label="$t('ui.mail.download')" class="text-muted-foreground hover:text-foreground">
                            <Download class="size-4" />
                        </a>
                    </li>
                </ul>
            </section>

            <!-- attachments the upload guard refused: never stored, so there is nothing to open -->
            <section v-if="email.refused.length" class="space-y-1 rounded-md border border-red-500/40 bg-red-500/5 p-3 text-sm">
                <p class="font-medium">{{ $t('ui.mail.refused_title') }}</p>
                <p v-for="r in email.refused" :key="r.name" class="text-muted-foreground"><span dir="auto">{{ r.name }}</span>: {{ r.reason }}</p>
            </section>

            <section v-if="thread.length" class="space-y-2">
                <p class="text-sm font-medium">{{ $t('ui.mail.thread') }}</p>
                <ul class="divide-y rounded-md border">
                    <li v-for="m in thread" :key="m.id">
                        <Link :href="route('mail.show', { email: m.id })" class="flex items-baseline justify-between gap-3 px-3 py-2 text-sm hover:bg-accent/40">
                            <span class="truncate">{{ m.direction === 'in' ? m.from_name || m.from_email : $t('ui.mail.our_reply') }} · {{ m.subject || $t('ui.mail.no_subject') }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ when(m.created_at) }}</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section v-if="can_send && email.direction === 'in'" class="space-y-3">
                <Button v-if="!replying" variant="outline" @click="replying = true"><Reply class="size-4 rtl:-scale-x-100" />{{ $t('ui.mail.reply') }}</Button>
                <form v-else class="space-y-3 rounded-lg border p-4" @submit.prevent="sendReply">
                    <p class="text-sm text-muted-foreground">{{ $t('ui.mail.reply_to', { to: email.from_email ?? '' }) }}</p>
                    <textarea
                        v-model="form.body"
                        rows="8"
                        dir="auto"
                        class="w-full rounded-md border border-input bg-background p-3 text-sm leading-7 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <InputError :message="form.errors.body" />
                    <div class="flex gap-3">
                        <Button :disabled="form.processing || !form.body.trim()">{{ $t('ui.mail.send') }}</Button>
                        <Button type="button" variant="ghost" @click="replying = false">{{ $t('ui.common.cancel') }}</Button>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ $t('ui.mail.queue_hint') }}</p>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
