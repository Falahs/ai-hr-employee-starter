import { router, usePage } from '@inertiajs/vue3';
import type { App } from 'vue';

/**
 * Translations come from lang/{ar,en}.json via the shared `translations` prop,
 * so the interface and Laravel's __() read the same file. A missing key shows the
 * key itself, which makes an untranslated string obvious on screen.
 */
type Params = Record<string, string | number>;

export function translate(key: string, params: Params = {}): string {
    const table = (usePage().props.translations ?? {}) as Record<string, string>;
    let text = table[key] ?? key;
    for (const [name, value] of Object.entries(params)) {
        text = text.replaceAll(`:${name}`, String(value));
    }
    return text;
}

/**
 * Pluralised: «:n سنة / سنتان / سنوات». Keys are `${key}.${form}` with the CLDR forms the
 * language uses (Arabic: zero, one, two, few, many, other; English: one, other).
 */
export function plural(key: string, n: number, params: Params = {}): string {
    const locale = (usePage().props.locale as string) ?? 'ar';
    const form = new Intl.PluralRules(locale).select(n);
    const table = (usePage().props.translations ?? {}) as Record<string, string>;
    const k = table[`${key}.${form}`] !== undefined ? `${key}.${form}` : `${key}.other`;
    return translate(k, { n, ...params });
}

export function useI18n() {
    const page = usePage();
    return {
        t: translate,
        locale: () => page.props.locale as string,
        dir: () => page.props.dir as string,
    };
}

/** Switching language is a full reload so the new table, direction and fonts all apply at once. */
export function setLocale(locale: string) {
    router.post(route('locale.update'), { locale }, { onSuccess: () => window.location.reload() });
}

/** Keeps <html lang dir> in step with the page after Inertia visits. */
export function syncDocumentDirection(props: { locale?: string; dir?: string }) {
    if (props.locale) document.documentElement.lang = props.locale;
    if (props.dir) document.documentElement.dir = props.dir;
}

export const i18n = {
    install(app: App) {
        app.config.globalProperties.$t = translate;
        app.config.globalProperties.$tp = plural;
    },
};

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        $t: typeof translate;
        $tp: typeof plural;
    }
}
