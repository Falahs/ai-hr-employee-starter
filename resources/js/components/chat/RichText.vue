<script setup lang="ts">
import { computed, h, type VNode } from 'vue';

/**
 * A small subset of Markdown for the assistant's answers: paragraphs, bulleted and numbered lists, a bold
 * line as a heading, **bold** and `code` inside a line. Built as Vue nodes, never as HTML: whatever the model
 * writes (a <script>, an <img onerror>) is shown as text, so no v-html and nothing for the page policy to catch.
 *
 * Read for an HR answer: the first sentence is the answer, so it stands out; a list item that opens with a
 * bold name puts the name on its own line and the details under it, smaller; « · » between details is muted,
 * so each detail reads on its own.
 */
const props = defineProps<{ text: string }>();

type Block = { kind: 'p' | 'ul' | 'ol' | 'h'; lines: string[] };

function blocks(src: string): Block[] {
    const out: Block[] = [];
    let cur: Block | null = null;
    const push = (kind: Block['kind'], line: string) => {
        if (!cur || cur.kind !== kind || kind === 'h') {
            cur = { kind, lines: [] };
            out.push(cur);
        }
        cur.lines.push(line);
    };
    for (const raw of src.replace(/\r\n/g, '\n').split('\n')) {
        const line = raw.trim();
        if (!line) {
            cur = null;
            continue;
        }
        let m: RegExpMatchArray | null;
        if ((m = line.match(/^[-*•]\s+(.*)$/))) push('ul', m[1]);
        else if ((m = line.match(/^\d+[.)]\s+(.*)$/))) push('ol', m[1]);
        else if ((m = line.match(/^#{1,4}\s+(.*)$/))) push('h', m[1]);
        // a line that is bold from end to end reads as a small heading
        else if ((m = line.match(/^\*\*([^*]+)\*\*:?$/))) push('h', m[1]);
        else push('p', line);
    }
    return out;
}

/**
 * A run of Latin text («St. Luke's Medical Center, Manila», «Staff Nurse, Med-Surg») set apart as one
 * left-to-right unit. Left to the browser inside an Arabic line, its commas and spaces made it break
 * apart and change places with the Arabic around it.
 */
const LATIN = /[A-Za-z][A-Za-z0-9 .,'’&()\/+#:-]*[A-Za-z0-9.)]|[A-Za-z]/g;
function isolated(text: string): (VNode | string)[] {
    const out: (VNode | string)[] = [];
    let last = 0;
    for (const m of text.matchAll(LATIN)) {
        const at = m.index ?? 0;
        if (at > last) out.push(text.slice(last, at));
        out.push(h('bdi', { dir: 'ltr', class: 'whitespace-normal' }, m[0]));
        last = at + m[0].length;
    }
    if (last < text.length) out.push(text.slice(last));
    return out;
}

/** « · » muted, so the parts of a line read apart. */
function separated(text: string): (VNode | string)[] {
    return text
        .split(/\s+·\s+/)
        .flatMap((part, i) => (i ? [h('span', { class: 'mx-1.5 text-muted-foreground/60', 'aria-hidden': 'true' }, '·'), ...isolated(part)] : isolated(part)));
}

/** **bold** and `code` inside a line; everything else is text. */
function inline(line: string): (VNode | string)[] {
    const parts: (VNode | string)[] = [];
    const re = /\*\*([^*]+)\*\*|`([^`]+)`/g;
    let last = 0;
    let m: RegExpExecArray | null;
    while ((m = re.exec(line))) {
        if (m.index > last) parts.push(...separated(line.slice(last, m.index)));
        parts.push(m[1] !== undefined ? h('strong', { class: 'font-semibold text-foreground' }, isolated(m[1])) : h('code', { class: 'rounded bg-background px-1 text-xs', dir: 'ltr' }, m[2]));
        last = re.lastIndex;
    }
    if (last < line.length) parts.push(...separated(line.slice(last)));
    return parts;
}

/** «**الجهة:** القيمة»: a labelled fact. */
const FACT = /^\*\*([^*]+?):\*\*\s*(.+)$|^\*\*([^*]+?)\*\*:\s*(.+)$/;
const isFact = (line: string) => FACT.test(line);

/** A run of facts about one thing: label and value side by side, like a small card. */
function facts(lines: string[]): VNode {
    return h(
        'dl',
        { class: 'grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-lg border bg-muted/30 px-3 py-2.5' },
        lines.flatMap((l) => {
            const m = l.match(FACT)!;
            const [label, value] = m[1] !== undefined ? [m[1], m[2]] : [m[3], m[4]];
            return [h('dt', { class: 'text-[13px] text-muted-foreground' }, isolated(label)), h('dd', { class: 'font-medium text-foreground' }, inline(value))];
        }),
    );
}

/** A list item that opens with a bold name: the name on its own line, the details under it. */
function item(line: string): VNode {
    const m = line.match(/^\*\*([^*]+)\*\*\s*(?:[·:،-]\s*)?(.*)$/);
    if (!m) return h('li', { class: 'ps-1' }, inline(line));

    return h('li', { class: 'ps-1' }, [
        h('span', { class: 'block font-semibold text-foreground' }, isolated(m[1])),
        m[2] ? h('span', { class: 'block text-[13px] leading-6 text-muted-foreground' }, inline(m[2])) : null,
    ]);
}

const render = computed(() =>
    blocks(props.text).map((b, i) => {
        if (b.kind === 'h') return h('p', { class: 'pt-1 text-sm font-semibold text-foreground' }, inline(b.lines[0]));
        if (b.kind === 'p') {
            // the opening sentence is the answer itself
            const lead = i === 0 ? 'text-[15px] font-medium text-foreground' : '';
            return h('p', { class: lead }, b.lines.flatMap((l, j) => (j ? [h('br'), ...inline(l)] : inline(l))));
        }
        // a bulleted list made only of labelled facts reads as a small card, not as bullets
        if (b.kind === 'ul' && b.lines.every(isFact)) return facts(b.lines);
        return h(
            b.kind,
            { class: (b.kind === 'ul' ? 'list-disc' : 'list-decimal') + ' space-y-2.5 ps-5 marker:text-[var(--viz-1)]' },
            b.lines.map((l) => item(l)),
        );
    }),
);
</script>

<template>
    <div dir="auto" class="space-y-3">
        <component :is="node" v-for="(node, i) in render" :key="i" />
    </div>
</template>
