<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { translate as t, useI18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Circle, Inbox, MailWarning, MessagesSquare, Send } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    mail: {
        unread: number;
        received_today: number;
        queued: number;
        failed: number;
        latest: { id: number; from_email: string; from_name: string | null; subject: string | null; read_at: string | null; created_at: string }[];
    } | null;
    chats: number | null;
    setup: { ai_key: boolean; mailbox: boolean; receiving: boolean; sending: boolean; team: number } | null;
}>();

const { locale } = useI18n();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('ui.nav.dashboard'), href: '/dashboard' }];
const tag = computed(() => (locale() === 'ar' ? 'ar-SA' : 'en-US'));
const n = (v: number) => new Intl.NumberFormat(tag.value).format(v);
const when = (iso: string) => new Date(iso).toLocaleString(tag.value, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

type Tile = { key: string; icon: typeof Inbox; value: number; href: string };
const tiles = computed<Tile[]>(() => {
    const out: Tile[] = [];
    const m = props.mail;
    if (m) {
        out.push(
            { key: 'unread', icon: Inbox, value: m.unread, href: route('mail') },
            { key: 'received_today', icon: Inbox, value: m.received_today, href: route('mail') },
            { key: 'queued', icon: Send, value: m.queued, href: route('mail', { box: 'out' }) },
            { key: 'failed', icon: MailWarning, value: m.failed, href: route('mail', { box: 'out' }) },
        );
    }
    if (props.chats !== null) out.push({ key: 'chats', icon: MessagesSquare, value: props.chats, href: route('chat') });
    return out;
});

// the admin's checklist: what is still missing before the system works end to end
const steps = computed(() =>
    props.setup
        ? [
              { key: 'ai_key', done: props.setup.ai_key, href: route('admin.settings', { section: 'ai' }) },
              { key: 'mailbox', done: props.setup.mailbox, href: route('admin.settings', { section: 'mail' }) },
              { key: 'receiving', done: props.setup.receiving, href: route('admin.settings', { section: 'mail' }) },
              { key: 'sending', done: props.setup.sending, href: route('admin.settings', { section: 'mail' }) },
              { key: 'team', done: props.setup.team > 2, href: route('admin.team') },
          ]
        : [],
);
</script>

<template>
    <Head :title="$t('ui.nav.dashboard')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 px-4 py-6">
            <Heading :title="$t('ui.home.title')" :description="$t('ui.home.description')" />

            <p v-if="!tiles.length" class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">{{ $t('ui.home.no_access') }}</p>

            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <Link v-for="tile in tiles" :key="tile.key" :href="tile.href" class="rounded-lg border p-4 transition-colors hover:bg-accent/40">
                    <p class="flex items-center gap-2 text-sm text-muted-foreground"><component :is="tile.icon" class="size-4" />{{ $t('ui.home.tile.' + tile.key) }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ n(tile.value) }}</p>
                </Link>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section v-if="mail" class="space-y-4 rounded-lg border p-4">
                    <HeadingSmall :title="$t('ui.home.latest')" />
                    <p v-if="!mail.latest.length" class="text-sm text-muted-foreground">{{ $t('ui.mail.empty_in') }}</p>
                    <ul v-else class="divide-y">
                        <li v-for="e in mail.latest" :key="e.id">
                            <Link :href="route('mail.show', { email: e.id })" class="flex items-baseline justify-between gap-3 py-2 text-sm hover:underline">
                                <span class="min-w-0">
                                    <span class="block truncate" :class="{ 'font-semibold': !e.read_at }">{{ e.subject || $t('ui.mail.no_subject') }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">{{ e.from_name || e.from_email }}</span>
                                </span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ when(e.created_at) }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section v-if="setup" class="space-y-4 rounded-lg border p-4">
                    <HeadingSmall :title="$t('ui.home.setup.title')" :description="$t('ui.home.setup.hint')" />
                    <ol class="space-y-2">
                        <li v-for="s in steps" :key="s.key">
                            <Link :href="s.href" class="flex items-start gap-2 text-sm hover:underline">
                                <CheckCircle2 v-if="s.done" class="mt-0.5 size-4 shrink-0 text-green-600" />
                                <Circle v-else class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <span :class="{ 'text-muted-foreground line-through': s.done }">{{ $t('ui.home.setup.' + s.key) }}</span>
                            </Link>
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
