import { reactive } from 'vue';

/**
 * Status global form transaksi cepat, supaya bisa dibuka dari mana saja
 * (tombol +, shortcut N, command palette, klik baris transaksi).
 */
const state = reactive({
    open: false,
    transaction: null, // diisi saat mengedit
    template: null, // diisi saat menduplikat: isian awal untuk transaksi baru
    type: 'expense',
    nonce: 0, // naik setiap kali dibuka, agar form direset walau sheet sudah terbuka
});

export function openQuickAdd({ transaction = null, template = null, type = 'expense' } = {}) {
    state.transaction = transaction;
    state.template = template;
    state.type = transaction?.type ?? template?.type ?? type;
    state.open = true;
    state.nonce++;
}

export function closeQuickAdd() {
    state.open = false;
}

export function useQuickAdd() {
    return state;
}
