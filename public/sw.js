// Service worker minimal: aset build (nama berhash) disimpan permanen,
// halaman & data selalu dari jaringan agar angka tidak pernah basi.
const CACHE = 'sisih-assets-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== location.origin || !url.pathname.startsWith('/build/')) return;

    event.respondWith(
        caches.open(CACHE).then(async (cache) => {
            const hit = await cache.match(event.request);
            if (hit) return hit;
            const response = await fetch(event.request);
            if (response.ok) cache.put(event.request, response.clone());
            return response;
        }),
    );
});
