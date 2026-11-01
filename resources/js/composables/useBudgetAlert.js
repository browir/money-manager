import { pushToast } from '@/composables/useToast';

/**
 * Peringatan anggaran setelah menyimpan pengeluaran: muncul sekali saat sebuah
 * kategori melewati 85% atau 100% anggarannya periode ini.
 */
const ratio = (c) => (c.budget && !c.urgent ? c.spent / c.budget : 0);

/** Rekam rasio terpakai sebelum menyimpan. */
export function snapshotBudgets(categories) {
    return Object.fromEntries(categories.map((c) => [c.id, ratio(c)]));
}

/** Bandingkan dengan kategori terbaru dari server (props halaman setelah simpan). */
export function alertBudgets(before, categories = []) {
    for (const c of categories) {
        const was = before[c.id] ?? 0;
        const now = ratio(c);
        if (now > 1 && was <= 1) {
            pushToast({ message: `Anggaran ${c.name} terlampaui`, tone: 'danger' });
        } else if (now >= 0.85 && was < 0.85 && now <= 1) {
            pushToast({ message: `Anggaran ${c.name} tinggal ${Math.max(0, Math.round((1 - now) * 100))}%`, tone: 'warn' });
        }
    }
}
