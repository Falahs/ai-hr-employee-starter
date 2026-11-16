<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';

/** Permission checkboxes: one per entry of app/Enums/Permission.php. */
defineProps<{
    catalog: { value: string }[];
    disabled?: boolean;
}>();
const model = defineModel<string[]>({ default: () => [] });

const toggle = (p: string, on: boolean) => {
    model.value = on ? [...new Set([...model.value, p])] : model.value.filter((x) => x !== p);
};
</script>

<template>
    <div class="grid gap-2 sm:grid-cols-2">
        <label
            v-for="p in catalog"
            :key="p.value"
            :for="'perm-' + p.value"
            class="flex cursor-pointer items-start gap-3 rounded-md border p-3 hover:bg-accent/40"
            :class="{ 'opacity-60': disabled }"
        >
            <Checkbox
                :id="'perm-' + p.value"
                class="mt-0.5"
                :checked="model.includes(p.value)"
                :disabled="disabled"
                @update:checked="(on: boolean) => toggle(p.value, on)"
            />
            <span class="grid gap-0.5">
                <span class="text-sm font-medium">{{ $t('ui.permission.' + p.value) }}</span>
                <span class="text-xs text-muted-foreground">{{ $t('ui.permission_hint.' + p.value) }}</span>
            </span>
        </label>
    </div>
</template>
