/**
 * Kalimat TETAP yang diucapkan LAX. Dipakai aplikasi (lib/lax.js) DAN pembuat paket suara
 * (scripts/lax-paket-suara.mjs), jadi teksnya harus sama persis agar audio paket cocok.
 * Tanpa nominal. Mengubah kalimat di sini = buat ulang paket suara untuk kalimat itu.
 * Berkas ini sengaja tanpa import (bisa dijalankan langsung oleh Node).
 */

export const LAX_SPOKEN = 'Laks'; // "LAX" huruf kapital dieja L-A-X oleh mesin suara

/**
 * Siapkan teks untuk dibacakan: "LAX" diucapkan sebagai satu kata (bukan dieja), dan nominal
 * "Rp 25.000" dibaca wajar "25.000 rupiah" (hanya muncul bila pengguna meminta nominal diucapkan).
 */
export function forSpeech(text) {
    // Angka format Indonesia (titik ribuan, koma desimal) tanpa ikut menelan tanda baca kalimat.
    const amount = String.raw`(\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?)`;
    return text
        .replace(new RegExp(String.raw`[-−]\s?Rp\s?` + amount, 'g'), 'minus $1 rupiah')
        .replace(new RegExp(String.raw`Rp\s?` + amount, 'g'), '$1 rupiah')
        .replace(/\bLAX\b/gi, LAX_SPOKEN);
}

/** Sebutan hormat untuk pemilik (permintaan pemilik). */
export const honorifics = (nick) => (nick ? ['Tuan', `Tuan ${nick}`, 'Tuan Muda'] : ['Tuan', 'Tuan Muda']);

export const GREETINGS = {
    pagi: [(t) => `Selamat pagi, ${t}. LAX siap membantu hari ini.`, (t) => `Pagi, ${t}! Mari mulai hari dengan rapi.`],
    siang: [(t) => `Selamat siang, ${t}. Ada yang perlu LAX catat?`, (t) => `Siang, ${t}! Habis jajan siang? Sebutkan saja, LAX catat.`],
    sore: [(t) => `Selamat sore, ${t}. Mau catat sesuatu atau cek keuangan?`, (t) => `Sore, ${t}! LAX siap mendengarkan.`],
    malam: [(t) => `Selamat malam, ${t}. Mari tutup hari dengan mencatat transaksi.`, (t) => `Malam, ${t}. Ada yang terlewat dicatat hari ini?`],
};

export const WAKE = [
    (t) => `Ya, ${t}? LAX di sini.`,
    (t) => `Siap, ${t}. Ada yang bisa LAX bantu?`,
    (t) => `Untuk Anda, ${t}, LAX selalu siap.`,
    (t) => `Silakan, ${t}.`,
];

export const PROPOSAL = [
    (t) => `LAX sudah siapkan, ${t}. Mohon dicek dulu sebelum dijalankan.`,
    (t) => `Ini usulan LAX, ${t}. Silakan periksa, lalu tekan jalankan.`,
];

export const ACK = [(t) => `Baik, ${t}.`, (t) => `Tentu, ${t}.`, (t) => `Siap, ${t}.`];

export const DONE_OPENERS = [(t) => `Beres, ${t}!`, (t) => `Siap, ${t}!`, (t) => `Laksanakan, ${t}!`, (t) => `Sudah beres, ${t}!`];

/** Kalimat hasil yang umum (tanpa nama akun/nominal) agar bisa diputar dari paket. */
const COUNT_WORDS = { 2: 'Dua', 3: 'Tiga', 4: 'Empat', 5: 'Lima' };
export const DONE = {
    expense: 'Pengeluaran sudah dicatat.',
    income: 'Pemasukan sudah dicatat.',
    transfer: 'Transfer sudah dicatat.',
    many: (n) => (COUNT_WORDS[n] ? `${COUNT_WORDS[n]} transaksi sudah dicatat.` : 'Semua transaksi sudah dicatat.'),
    create: 'Akun baru sudah dibuat.',
    rename: 'Nama akun sudah diganti.',
    adjust_balance: 'Saldo sudah disesuaikan.',
    archive: 'Akun sudah diarsipkan.',
    unarchive: 'Akun sudah aktif lagi.',
};

export const SAMPLE = 'Halo, Tuan. Saya LAX, asisten pribadi Anda. Semangat mencatat keuangan hari ini, ya!';

/**
 * Semua kalimat tetap untuk satu nama panggilan, URUT PRIORITAS (yang paling sering diucapkan
 * dulu), supaya pembuatan paket bertahap dengan kuota gratis tetap berguna sejak hari pertama.
 * Teks yang dikembalikan sudah lewat forSpeech().
 */
export function catalog(nick) {
    const ts = honorifics(nick);
    const each = (fns) => ts.flatMap((t) => fns.map((fn) => fn(t)));
    return [
        ...each(DONE_OPENERS),
        DONE.expense, DONE.income, DONE.transfer, DONE.adjust_balance,
        ...each(ACK),
        ...each(PROPOSAL),
        ...each(WAKE),
        ...each(Object.values(GREETINGS).flat()),
        DONE.many(2), DONE.many(3), DONE.many(4), DONE.many(5), DONE.many(6),
        DONE.create, DONE.rename, DONE.archive, DONE.unarchive,
        SAMPLE,
    ].map(forSpeech).filter((text, i, all) => all.indexOf(text) === i);
}
