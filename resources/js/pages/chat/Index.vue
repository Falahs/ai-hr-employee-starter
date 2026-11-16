<script setup lang="ts">
import RichText from '@/components/chat/RichText.vue';
import { Button } from '@/components/ui/button';
import { translate as t, useI18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Loader2, MessageSquarePlus, Pencil, SendHorizontal, Sparkles, Trash2 } from 'lucide-vue-next';
import { nextTick, onMounted, ref, watch } from 'vue';

interface Message {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    created_at: string;
}
const props = defineProps<{
    sessions: { id: number; title: string | null; updated_at: string }[];
    current: { id: number; title: string | null; messages: Message[] } | null;
}>();

const { locale } = useI18n();
const tag = () => (locale() === 'ar' ? 'ar-SA' : 'en-US');
// a message's time: the hour today, the date too on any other day
const stamp = (iso: string) => {
    const d = new Date(iso);
    const today = d.toDateString() === new Date().toDateString();
    return d.toLocaleString(tag(), today ? { hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
};
const breadcrumbs: BreadcrumbItem[] = [{ title: t('ui.chat.title'), href: '/chat' }];
const when = (iso: string) => new Date(iso).toLocaleString(tag(), { dateStyle: 'short', timeStyle: 'short' });

const form = useForm({ message: '' });
const pending = ref<string | null>(null); // the question shown while the answer is on its way
const scroller = ref<HTMLElement | null>(null);
const toBottom = () => nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight }));
onMounted(toBottom);
watch(() => props.current?.messages.length, toBottom);

const send = () => {
    if (!props.current || !form.message.trim()) return;
    pending.value = form.message;
    form.post(route('chat.send', { session: props.current.id }), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onFinish: () => (pending.value = null),
    });
    toBottom();
};
const newSession = () => router.post(route('chat.store'));
const rename = () => {
    if (!props.current) return;
    const title = prompt(t('ui.chat.rename_prompt'), props.current.title ?? '');
    if (title?.trim()) router.put(route('chat.rename', { session: props.current.id }), { title: title.trim() }, { preserveScroll: true });
};
const remove = () => {
    if (props.current && confirm(t('ui.chat.delete_confirm'))) router.delete(route('chat.destroy', { session: props.current.id }));
};
const examples = [t('ui.chat.example.1'), t('ui.chat.example.2'), t('ui.chat.example.3')];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="current?.title ?? $t('ui.chat.title')" />

        <div class="grid h-[calc(100svh-4rem)] gap-4 p-4 md:grid-cols-[15rem_1fr]">
            <!-- sessions -->
            <aside class="flex min-h-0 flex-col gap-2">
                <Button @click="newSession"><MessageSquarePlus class="size-4" />{{ $t('ui.chat.new') }}</Button>
                <p class="px-1 text-xs text-muted-foreground">{{ $t('ui.chat.sessions_hint') }}</p>
                <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto">
                    <Link
                        v-for="s in sessions"
                        :key="s.id"
                        :href="route('chat.show', { session: s.id })"
                        class="block truncate rounded-md px-3 py-2 text-sm hover:bg-accent"
                        :class="{ 'bg-muted font-medium': current?.id === s.id }"
                    >
                        {{ s.title ?? $t('ui.chat.untitled') }}
                        <span class="block text-xs font-normal text-muted-foreground">{{ when(s.updated_at) }}</span>
                    </Link>
                </nav>
            </aside>

            <!-- conversation -->
            <section class="flex min-h-0 flex-col rounded-lg border">
                <template v-if="current">
                    <header class="flex items-center gap-2 border-b p-3">
                        <span class="flex-1 truncate font-medium">{{ current.title ?? $t('ui.chat.untitled') }}</span>
                        <Button size="icon" variant="ghost" :aria-label="$t('ui.common.edit')" @click="rename"><Pencil class="size-4" /></Button>
                        <Button size="icon" variant="ghost" :aria-label="$t('ui.common.delete')" @click="remove"><Trash2 class="size-4" /></Button>
                    </header>

                    <div ref="scroller" class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                        <div v-if="!current.messages.length && !pending" class="space-y-3 py-8 text-center text-sm text-muted-foreground">
                            <p>{{ $t('ui.chat.empty') }}</p>
                            <div class="flex flex-wrap justify-center gap-2">
                                <button v-for="e in examples" :key="e" type="button" class="rounded-full border px-3 py-1 hover:bg-accent" @click="form.message = e">{{ e }}</button>
                            </div>
                        </div>
                        <div v-for="m in current.messages" :key="m.id" class="flex flex-col" :class="m.role === 'user' ? 'items-start' : 'items-end'">
                            <div v-if="m.role === 'user'" class="max-w-[75%] rounded-2xl rounded-ss-md bg-primary px-4 py-2.5 text-sm leading-7 text-primary-foreground shadow-sm">
                                <p dir="auto" class="whitespace-pre-wrap">{{ m.content }}</p>
                            </div>
                            <div v-else class="w-full max-w-[85%] overflow-hidden rounded-2xl rounded-se-md border bg-card text-sm leading-7 shadow-sm">
                                <p class="flex items-center gap-1.5 border-b bg-muted/40 px-4 py-1.5 text-xs font-medium text-muted-foreground">
                                    <Sparkles class="size-3.5 text-[var(--viz-1)]" />{{ $t('ui.chat.assistant') }}
                                </p>
                                <div class="px-4 py-3">
                                    <RichText :text="m.content" />
                                </div>
                            </div>
                            <time :datetime="m.created_at" class="mt-1 px-1 text-[11px] text-muted-foreground">{{ stamp(m.created_at) }}</time>
                        </div>
                        <div v-if="pending" class="flex justify-start">
                            <p dir="auto" class="max-w-[75%] whitespace-pre-wrap rounded-2xl rounded-ss-md bg-primary px-4 py-2.5 text-sm text-primary-foreground opacity-70">{{ pending }}</p>
                        </div>
                        <div v-if="pending" class="flex justify-end">
                            <p class="flex items-center gap-2 rounded-2xl rounded-se-md border bg-card px-4 py-2.5 text-sm text-muted-foreground shadow-sm"><Loader2 class="size-4 animate-spin" />{{ $t('ui.chat.thinking') }}</p>
                        </div>
                    </div>

                    <form class="flex gap-2 border-t p-3" @submit.prevent="send">
                        <textarea
                            v-model="form.message"
                            rows="2"
                            dir="auto"
                            :placeholder="$t('ui.chat.placeholder')"
                            class="flex-1 resize-none rounded-md border border-input bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @keydown.enter.exact.prevent="send"
                        />
                        <Button :disabled="form.processing || !form.message.trim()" :aria-label="$t('ui.chat.send')"><SendHorizontal class="size-4 rtl:-scale-x-100" /></Button>
                    </form>
                    <p v-if="form.errors.message" class="px-3 pb-2 text-xs text-red-600">{{ form.errors.message }}</p>
                </template>

                <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center text-sm text-muted-foreground">
                    <p>{{ $t('ui.chat.pick') }}</p>
                    <Button variant="outline" @click="newSession"><MessageSquarePlus class="size-4" />{{ $t('ui.chat.new') }}</Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
