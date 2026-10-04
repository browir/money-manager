import { onBeforeUnmount, onMounted } from 'vue';

const isTyping = (el) =>
    el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));

/**
 * Daftarkan shortcut keyboard selama komponen hidup.
 * Kunci: "n", "/", "arrowleft", "mod+k" (Ctrl/Cmd+K).
 * Shortcut tanpa modifier diabaikan saat sedang mengetik atau ada dialog terbuka.
 */
export function useShortcuts(map) {
    const handler = (e) => {
        const mod = e.ctrlKey || e.metaKey;
        const key = (mod ? 'mod+' : '') + e.key.toLowerCase();
        const fn = map[key];
        if (!fn) return;
        if (!mod && (isTyping(e.target) || e.altKey || document.querySelector('[data-modal-open]'))) return;
        e.preventDefault();
        fn(e);
    };

    onMounted(() => window.addEventListener('keydown', handler));
    onBeforeUnmount(() => window.removeEventListener('keydown', handler));
}
