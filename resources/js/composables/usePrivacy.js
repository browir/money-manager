import { ref } from 'vue';

/**
 * Mode privasi: semua elemen ber-kelas `.amount` diburamkan (lihat app.css).
 * Berguna saat membuka aplikasi di tempat umum.
 */
const KEY = 'sisih:private';

function read() {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}

const hidden = ref(read());

export function togglePrivacy() {
    hidden.value = !hidden.value;
    document.documentElement.toggleAttribute('data-private', hidden.value);
    navigator.vibrate?.(6);
    try {
        localStorage.setItem(KEY, hidden.value ? '1' : '0');
    } catch {
        // Penyimpanan diblokir: tetap berlaku untuk sesi ini.
    }
}

export function usePrivacy() {
    return hidden;
}
