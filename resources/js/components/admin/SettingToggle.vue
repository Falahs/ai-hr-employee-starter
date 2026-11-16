<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { computed } from 'vue';

/** A yes/no setting stored as '1' / '0'. */
const props = defineProps<{ id: string; label: string; hint?: string }>();
const model = defineModel<string>({ default: '0' });

const checked = computed({
    get: () => model.value === '1',
    set: (v: boolean) => (model.value = v ? '1' : '0'),
});
</script>

<template>
    <label :for="props.id" class="flex cursor-pointer items-start gap-3 rounded-md border p-3 hover:bg-accent/40">
        <Checkbox :id="props.id" v-model:checked="checked" class="mt-0.5" />
        <span class="grid gap-1">
            <span class="text-sm font-medium">{{ label }}</span>
            <span v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</span>
        </span>
    </label>
</template>
