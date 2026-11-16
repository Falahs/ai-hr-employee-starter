<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { translate as t, useI18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Paperclip, PenSquare, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Row {
    id: number;
    direction: 'in' | 'out';
    status: string;
    from_email: string | null;
    from_name: string | null;
    to: string[];
    subject: string | null;
    read_at: string | null;
    created_at: string;
    sent_at: string | null;
    attachments_count: number;
}

const props = defineProps<{
    box: 'in' | 'out';
    q: string;
    emails: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    unread: number;
    mailbox: { address: string | null; receiving: boolean; sending: boolean };
}>();

const page = usePage<SharedData>();
const canSend = computed(() => page.props.auth.user?.permissions?.includes('email.send') ?? false);

const { locale } = useI18n();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('ui.nav.mail'), href: '/mail' }];
const when = (iso: string) => new Date(iso).toLocaleString(locale() === 'ar' ? 'ar-SA' : 'en-US', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const search = ref(props.q);
const go = (box: string) => router.get(route('mail'), { box, q: search.value || undefined }, { preserveState: true });

// Laravel's pagination labels come as «&laquo; Previous»: shown as plain arrows, never as HTML
const label = (l: string) => (l.includes('Previous') ? '‹' : l.includes('Next') ? '›' : l);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="$t('ui.nav.mail')" />

        <div class="space-y-6 px-4 py-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="$t('ui.nav.mail')" :description="mailbox.address ? $t('ui.mail.description', { address: mailbox.address }) : $t('ui.mail.not_connected')" />
                <Button v-if="canSend" as-child>
                    <Link :href="route('mail.compose')"><PenSquare class="size-4" />{{ $t('ui.mail.compose') }}</Link>
                </Button>
            </div>

            <p v-if="!mailbox.receiving || !mailbox.sending" class="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                {{ !mailbox.receiving ? $t('ui.mail.receiving_off') : '' }} {{ !mailbox.sending ? $t('ui.mail.sending_off') : '' }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <Button :variant="box === 'in' ? 'default' : 'outline'" size="sm" @click="go('in')">
                    {{ $t('ui.mail.inbox') }}<span v-if="unread" class="rounded bg-background/20 px-1.5 text-xs tabular-nums">{{ unread }}</span>
                </Button>
                <Button :variant="box === 'out' ? 'default' : 'outline'" size="sm" @click="go('out')">{{ $t('ui.mail.outbox') }}</Button>
                <form class="ms-auto flex gap-2" @submit.prevent="go(box)">
                    <Input v-model="search" :placeholder="$t('ui.mail.search')" class="h-9 w-56" />
                    <Button type="submit" size="icon" variant="outline" :aria-label="$t('ui.mail.search')"><Search class="size-4" /></Button>
                </form>
            </div>

            <div class="overflow-hidden rounded-lg border">
                <p v-if="!emails.data.length" class="p-6 text-center text-sm text-muted-foreground">
                    {{ box === 'in' ? $t('ui.mail.empty_in') : $t('ui.mail.empty_out') }}
                </p>
                <ul v-else class="divide-y">
                    <li v-for="e in emails.data" :key="e.id">
                        <Link :href="route('mail.show', { email: e.id })" class="flex items-start gap-3 px-4 py-3 hover:bg-accent/40">
                            <span class="mt-2 size-2 shrink-0 rounded-full" :class="e.direction === 'in' && !e.read_at ? 'bg-[var(--viz-1)]' : 'bg-transparent'" />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm" :class="{ 'font-semibold': e.direction === 'in' && !e.read_at }">
                                        {{ e.direction === 'in' ? e.from_name || e.from_email : $t('ui.mail.to', { to: e.to.join(', ') }) }}
                                    </span>
                                    <span class="shrink-0 text-xs text-muted-foreground">{{ when(e.sent_at ?? e.created_at) }}</span>
                                </span>
                                <span class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <span class="truncate">{{ e.subject || $t('ui.mail.no_subject') }}</span>
                                    <Paperclip v-if="e.attachments_count" class="size-3.5 shrink-0" />
                                    <span v-if="e.direction === 'out'" class="shrink-0 rounded border px-1.5 text-xs" :class="{ 'border-red-500/50 text-red-600': e.status === 'failed' }">
                                        {{ $t('ui.mail.status.' + e.status) }}
                                    </span>
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>

            <nav v-if="emails.links.length > 3" class="flex flex-wrap gap-1">
                <template v-for="(l, i) in emails.links" :key="i">
                    <Button v-if="l.url" size="sm" :variant="l.active ? 'default' : 'outline'" as-child>
                        <Link :href="l.url" preserve-scroll>{{ label(l.label) }}</Link>
                    </Button>
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
