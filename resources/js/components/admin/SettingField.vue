<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** One labelled setting input. `ltr` for values that are always Latin (hosts, keys, model names). */
defineProps<{
    id: string;
    label: string;
    hint?: string;
    error?: string;
    type?: string;
    ltr?: boolean;
    placeholder?: string;
    autocomplete?: string;
}>();

const model = defineModel<string>({ default: '' });
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <Input
            :id="id"
            v-model="model"
            :type="type ?? 'text'"
            :dir="ltr ? 'ltr' : undefined"
            :placeholder="placeholder"
            :autocomplete="autocomplete ?? 'off'"
            :class="ltr ? 'text-start' : ''"
        />
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        <InputError :message="error" />
    </div>
</template>
