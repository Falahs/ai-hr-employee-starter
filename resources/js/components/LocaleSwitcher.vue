<script setup lang="ts">
import { setLocale, useI18n } from '@/i18n';
import { Languages } from 'lucide-vue-next';

const { locale } = useI18n();
const other = () => (locale() === 'ar' ? 'en' : 'ar');
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-accent hover:text-foreground"
        @click="setLocale(other())"
    >
        <Languages class="size-4" />
        <span>{{ $t('ui.locale.switch_to.' + other()) }}</span>
    </button>
</template>
