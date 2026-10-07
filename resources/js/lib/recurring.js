import { fromIso, shortDate, today } from '@/lib/dates';

export const FREQUENCIES = [
    { value: 'weekly', label: 'Mingguan' },
    { value: 'monthly', label: 'Bulanan' },
    { value: 'yearly', label: 'Tahunan' },
];

const weekdayFmt = new Intl.DateTimeFormat('id-ID', { weekday: 'long' });
const dayMonthFmt = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long' });

/** "Tiap Senin", "Tiap bulan, tgl 25", "Tiap tahun, 17 Agustus". */
export function frequencyLabel(r) {
    const d = fromIso(r.start_on);
    if (r.frequency === 'weekly') return `Tiap ${weekdayFmt.format(d)}`;
    if (r.frequency === 'yearly') return `Tiap tahun, ${dayMonthFmt.format(d)}`;
    return `Tiap bulan, tgl ${d.getDate()}`;
}

/** Selisih hari dari hari ini (negatif = terlambat). */
export function daysUntil(iso) {
    return Math.round((fromIso(iso) - fromIso(today())) / 86_400_000);
}

/** "Hari ini", "Besok", "Terlambat 2 hari", "5 hari lagi", "12 Nov". */
export function dueLabel(iso) {
    const n = daysUntil(iso);
    if (n < 0) return `Terlambat ${-n} hari`;
    if (n === 0) return 'Hari ini';
    if (n === 1) return 'Besok';
    if (n <= 7) return `${n} hari lagi`;
    return shortDate(iso);
}
