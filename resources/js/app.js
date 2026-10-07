import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import AppLayout from './layouts/AppLayout.vue';
import { highlightTransaction } from './composables/useCelebration';
import { startOutbox } from './composables/useOutbox';
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
    const { toast, saved } = event.detail.flash ?? {};
    if (toast) pushToast(toast);
    if (saved) highlightTransaction(saved);
});

// Koneksi putus saat pindah halaman / aksi: beri tahu, jangan biarkan galat tak tertangani.
router.on('networkError', (event) => {
    event.preventDefault();
    pushToast({ message: navigator.onLine ? 'Gagal terhubung ke server' : 'Sedang offline. Coba lagi saat tersambung.' });
});

// Keluar / sesi habis: hapus salinan halaman offline agar data tidak bisa dibuka tanpa login.
router.on('navigate', (event) => {
    if (event.detail.page.component.startsWith('auth/') && 'caches' in window) {
        caches.delete('sisih-data-v1');
        caches.open('sisih-pages-v1').then(async (cache) => {
            for (const req of await cache.keys()) if (!req.url.endsWith('/offline.html')) cache.delete(req);
        });
    }
});

startOutbox();

// Dibuka saat offline: HTML tersimpan bisa lebih tua dari salinan data Inertia terakhir, jadi muat ulang datanya.
if (!navigator.onLine) setTimeout(() => router.reload(), 0);

if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
