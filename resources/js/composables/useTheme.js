import { ref } from 'vue';

const KEY = 'sisih:theme';
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function read() {
    try {
        return localStorage.getItem(KEY) || 'system';
    } catch {
        return 'system';
    }
}

const preference = ref(read());

// Warna bilah status/PWA; samakan dengan --paper dan meta di app.blade.php.
const BAR_COLOR = { light: '#F6F5F1', dark: '#111317' };

function apply() {
    const dark = preference.value === 'dark' || (preference.value === 'system' && media?.matches);
    const theme = dark ? 'dark' : 'light';
    document.documentElement.dataset.theme = theme;
    // Meta bawaan mengikuti tema sistem; timpa agar ikut pilihan manual.
    document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.setAttribute('content', BAR_COLOR[theme]));
}

media?.addEventListener('change', apply);

export function setTheme(value) {
    preference.value = value;
    try {
        localStorage.setItem(KEY, value);
    } catch {
        // Penyimpanan diblokir: tema tetap berlaku untuk sesi ini.
    }
    apply();
}

export function toggleTheme() {
    setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
}

export function useTheme() {
    return preference;
}
