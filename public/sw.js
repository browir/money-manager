// Service worker Sisih.
// - Aset build (nama berhash): cache permanen.
// - Halaman & data Inertia: selalu dari jaringan dulu (angka tidak basi); salinan terakhir
//   disimpan agar aplikasi tetap bisa dibuka saat offline.
// - Transaksi baru saat offline diantre di klien (useOutbox), bukan di sini.
const ASSETS = 'sisih-assets-v1';
const PAGES = 'sisih-pages-v1';
const DATA = 'sisih-data-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(PAGES).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => ![ASSETS, PAGES, DATA].includes(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/_ops/')) return;

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));
    } else if (request.mode === 'navigate') {
        event.respondWith(networkFirst(PAGES, request, OFFLINE_URL));
    } else if (request.headers.get('X-Inertia') && !request.headers.get('X-Inertia-Partial-Component')) {
        event.respondWith(networkFirst(DATA, request));
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(ASSETS);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
}

async function networkFirst(cacheName, request, fallbackUrl) {
    const cache = await caches.open(cacheName);
    try {
        const response = await fetch(request);
        // Jangan simpan pengalihan (mis. ke /masuk) atau galat.
        if (response.ok && response.type === 'basic' && !response.redirected) cache.put(request, response.clone());
        return response;
    } catch (error) {
        // HTML dan JSON Inertia untuk URL yang sama ada di cache berbeda, jadi Vary aman diabaikan.
        const hit = await cache.match(request, { ignoreVary: true });
        if (hit) return hit;
        if (fallbackUrl) {
            const fallback = await cache.match(fallbackUrl);
            if (fallback) return fallback;
        }
        throw error;
    }
}
