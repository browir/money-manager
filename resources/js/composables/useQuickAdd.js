import { reactive } from 'vue';

/**
 * Status global form transaksi cepat, supaya bisa dibuka dari mana saja
 * (tombol +, shortcut N, command palette, klik baris transaksi).
 */
const state = reactive({
    open: false,
    transaction: null, // diisi saat mengedit
    type: 'expense',
});

export function openQuickAdd({ transaction = null, type = 'expense' } = {}) {
    state.transaction = transaction;
    state.type = transaction?.type ?? type;
    state.open = true;
}

export function closeQuickAdd() {
    state.open = false;
}

export function useQuickAdd() {
    return state;
}
