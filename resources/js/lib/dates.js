const pad = (n) => String(n).padStart(2, '0');

export function toIso(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function fromIso(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d || 1);
}

export function today() {
    return toIso(new Date());
}

export function addDays(iso, n) {
    const d = fromIso(iso);
    d.setDate(d.getDate() + n);
    return toIso(d);
}

export function currentMonth() {
    return today().slice(0, 7);
}

export function shiftMonth(month, n) {
    const d = fromIso(month + '-01');
    d.setMonth(d.getMonth() + n);
    return toIso(d).slice(0, 7);
}

const monthFmt = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });
const monthOnlyFmt = new Intl.DateTimeFormat('id-ID', { month: 'long' });
const dayFmt = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'short' });
const dayYearFmt = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
const shortFmt = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });

export function monthLabel(month, { withYear = true } = {}) {
    const d = fromIso(month + '-01');
    if (!withYear || month.slice(0, 4) === today().slice(0, 4)) return monthOnlyFmt.format(d);
    return monthFmt.format(d);
}

/** "Hari ini", "Kemarin", atau "Sabtu, 4 Okt". */
export function dayLabel(iso) {
    const t = today();
    if (iso === t) return 'Hari ini';
    if (iso === addDays(t, -1)) return 'Kemarin';
    const d = fromIso(iso);
    return (iso.slice(0, 4) === t.slice(0, 4) ? dayFmt : dayYearFmt).format(d);
}

export function shortDate(iso) {
    return shortFmt.format(fromIso(iso));
}

export function daysInMonth(month) {
    const d = fromIso(month + '-01');
    return new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
}

/* ---- Periode bulanan yang dimulai di tanggal gajian ----
   Kunci periode = bulan saat periode dimulai: dengan awal tanggal 25,
   "2026-10" = 25 Okt – 24 Nov. Awal tanggal 1 = bulan kalender biasa. */

/** Rentang periode, { start, end } dalam ISO. */
export function periodRange(key, startDay = 1) {
    const start = `${key}-${pad(startDay)}`;
    return { start, end: addDays(`${shiftMonth(key, 1)}-${pad(startDay)}`, -1) };
}

/** Kunci periode yang memuat tanggal ISO. */
export function periodOf(iso, startDay = 1) {
    const month = iso.slice(0, 7);
    return Number(iso.slice(8)) >= startDay ? month : shiftMonth(month, -1);
}

/** "25 Okt – 24 Nov" (hanya bermakna bila awal periode bukan tanggal 1). */
export function periodRangeLabel(key, startDay = 1) {
    const { start, end } = periodRange(key, startDay);
    return `${shortDate(start)} – ${shortDate(end)}`;
}

/** Jumlah hari dari a sampai b, inklusif. */
export function daysBetween(a, b) {
    return Math.round((fromIso(b) - fromIso(a)) / 86_400_000) + 1;
}
