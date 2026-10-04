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

function apply() {
    const dark = preference.value === 'dark' || (preference.value === 'system' && media?.matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
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
