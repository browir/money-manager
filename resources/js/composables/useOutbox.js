import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import { pushToast } from '@/composables/useToast';

/**
 * Antrean transaksi baru yang dicatat saat offline. Disimpan di localStorage (data kecil,
 * satu pengguna) dan dikirim berurutan begitu online. Server menolak duplikat lewat client_id,
 * jadi mengirim ulang selalu aman.
 *
 * Item: { client_id, payload, summary: { title, amount, type }, created_at, error? }
 */
const KEY = 'sisih:outbox';
const items = ref(read());
const sending = ref(false);
const needsLogin = ref(false);

function read() {
    try {
        return JSON.parse(localStorage.getItem(KEY) || '[]');
    } catch {
        return [];
    }
}

function write() {
    try {
        localStorage.setItem(KEY, JSON.stringify(items.value));
    } catch {
        // Penyimpanan penuh/diblokir: antrean tetap hidup selama tab terbuka.
    }
}

export function newClientId() {
    if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
    // Cadangan untuk konteks tidak aman (mis. http lewat IP lokal).
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export function enqueue(payload, summary) {
    if (items.value.some((i) => i.client_id === payload.client_id)) return;
    items.value = [...items.value, { client_id: payload.client_id, payload, summary, created_at: Date.now() }];
    write();
}

export function removeFromOutbox(clientId) {
    items.value = items.value.filter((i) => i.client_id !== clientId);
    write();
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** Kirim semua yang mengantre. Mengembalikan jumlah yang berhasil. */
export async function flushOutbox() {
    if (sending.value || !items.value.length || !navigator.onLine) return 0;
    sending.value = true;
    let sent = 0;

    try {
        for (const item of [...items.value]) {
            if (item.error) continue; // ditolak server: menunggu diperbaiki pengguna

            let response;
            try {
                response = await fetch(route('transactions.store'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify(item.payload),
                });
            } catch {
                break; // jaringan putus lagi: coba nanti
            }

            if (response.status === 401 || response.status === 419) {
                needsLogin.value = true; // sesi habis: kirim setelah masuk lagi
                break;
            }
            if (response.status === 422) {
                const body = await response.json().catch(() => ({}));
                const message = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? 'Data tidak valid';
                items.value = items.value.map((i) => (i.client_id === item.client_id ? { ...i, error: message } : i));
                write();
                continue;
            }
            if (!response.ok) break; // galat server: coba nanti

            removeFromOutbox(item.client_id);
            sent++;
        }
    } finally {
        sending.value = false;
    }

    if (sent) {
        needsLogin.value = false;
        pushToast({ message: sent === 1 ? 'Transaksi offline terkirim' : `${sent} transaksi offline terkirim` });
        router.reload();
    }
    return sent;
}

let started = false;

/** Dipanggil sekali saat aplikasi mulai: kirim saat online kembali / saat dibuka. */
export function startOutbox() {
    if (started || typeof window === 'undefined') return;
    started = true;
    window.addEventListener('online', () => flushOutbox());
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && flushOutbox());
    setTimeout(flushOutbox, 1500);
}

export function useOutbox() {
    return { items, sending, needsLogin };
}
