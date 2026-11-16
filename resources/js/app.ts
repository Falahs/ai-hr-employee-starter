import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import { i18n, syncDocumentDirection } from './i18n';

let appName = '';

createInertiaApp({
    title: (title) => (title ? `${title} | ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        appName = (props.initialPage.props.translations as Record<string, string> | undefined)?.['ui.app.name'] ?? '';
        syncDocumentDirection(props.initialPage.props as { locale?: string; dir?: string });
        router.on('navigate', (event) => syncDocumentDirection(event.detail.page.props as { locale?: string; dir?: string }));

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#5B4CFF',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
