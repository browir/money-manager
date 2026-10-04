import { reactive } from 'vue';

const toasts = reactive([]);
let seq = 0;

export function pushToast({ message, undo = null, tone = 'default' }) {
    const id = ++seq;
    toasts.push({ id, message, undo, tone });
    // Batasi tumpukan agar layar HP tidak penuh.
    while (toasts.length > 3) toasts.shift();
    setTimeout(() => dismissToast(id), undo ? 6000 : 3800);
}

export function dismissToast(id) {
    const i = toasts.findIndex((t) => t.id === id);
    if (i !== -1) toasts.splice(i, 1);
}

export function useToasts() {
    return toasts;
}
