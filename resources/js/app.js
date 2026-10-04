import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import AppLayout from './layouts/AppLayout.vue';
import { pushToast } from './composables/useToast';

const pages = import.meta.glob('./pages/**/*.vue', { eager: true });

createInertiaApp({
    title: (title) => (title ? `${title} · Sisih` : 'Sisih'),
    resolve: (name) => pages[`./pages/${name}.vue`],
    layout: (name) => (name.startsWith('auth/') ? null : AppLayout),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: 'var(--accent-text)',
        delay: 180,
    },
});

// Pesan sekali-tampil dari server (Inertia::flash('toast', ...)).
router.on('flash', (event) => {
    const toast = event.detail.flash?.toast;
    if (toast) pushToast(toast);
});

if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
