import { reactive, ref } from 'vue';

/* ---------------------------------------------------------------
   Animasi "tersimpan" setelah transaksi dicatat, plus sorotan
   baris baru di daftar.
   --------------------------------------------------------------- */
const state = reactive({
    show: false,
    key: 0,
    type: 'expense',
    amount: 0,
    label: '',
    subtitle: '',
    icon: null,
    color: 'slate',
});
let timer = null;

export function celebrate({ type, amount, label, subtitle = '', icon = null, color = 'slate', duration = 1700 }) {
    clearTimeout(timer);
    Object.assign(state, { type, amount, label, subtitle, icon, color, show: true, key: state.key + 1 });
    navigator.vibrate?.([10, 50, 18]);
    timer = setTimeout(dismissCelebration, duration);
}

export function dismissCelebration() {
    clearTimeout(timer);
    state.show = false;
}

export function useCelebration() {
    return state;
}

const highlighted = ref(null);
let highlightTimer = null;

export function highlightTransaction(id) {
    clearTimeout(highlightTimer);
    highlighted.value = id;
    highlightTimer = setTimeout(() => (highlighted.value = null), 3200);
}

export function useHighlighted() {
    return highlighted;
}
