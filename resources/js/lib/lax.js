/**
 * Identitas asisten suara Sisih: LAX — Ledger Assistant eXtraordinaire.
 * Nama & kepribadian di sini; prompt server ada di app/Support/VoiceAssistant.php.
 * Semua kalimat yang diucapkan TIDAK boleh berisi nominal.
 */
export const LAX = {
    name: 'LAX',
    full: 'Ledger Assistant eXtraordinaire',
    motto: 'Lincah, Akurat, eXtra peduli',
};

export const pick = (list) => list[Math.floor(Math.random() * list.length)];

const capitalize = (s) => s.charAt(0).toUpperCase() + s.slice(1);

/** Sapaan pembuka sesuai waktu, dengan nama depan pengguna. */
export function greeting(name) {
    const h = new Date().getHours();
    const n = name ? `, ${name}` : '';
    if (h < 11) return pick([`Pagi${n}! LAX siap bantu catat hari ini.`, `Selamat pagi${n}! Mulai hari dengan rapi, yuk.`]);
    if (h < 15) return pick([`Siang${n}! Ada yang mau LAX catat?`, `Halo${n}! Habis jajan siang? Sebut saja, LAX catat.`]);
    if (h < 19) return pick([`Sore${n}! Mau catat sesuatu atau cek keuangan?`, `Sore${n}! LAX siap mendengarkan.`]);
    return pick([`Malam${n}. Yuk tutup hari dengan mencatat transaksi.`, `Malam${n}! Ada yang terlewat dicatat hari ini?`]);
}

/** Jawaban saat dipanggil "Hei LAX" (setelah sapaan pertama sesi ini). */
export function wakeLine(name) {
    const n = name ? `, ${name}` : '';
    return pick([`Ya${n}? LAX di sini.`, `Hai${n}! Ada yang bisa LAX bantu?`, `LAX siap! Mau catat apa${n}?`, `Dengan senang hati${n}. Silakan.`]);
}

export const PROPOSAL_LINES = [
    'LAX sudah siapkan. Cek dulu ya, lalu tekan jalankan.',
    'Ini usulan LAX. Cek sebentar sebelum dijalankan, ya.',
];
export const ANSWER_LINES = ['Jawabannya sudah LAX tampilkan di layar.', 'Sudah LAX tampilkan di layar, ya.'];
export const DONE_OPENERS = ['Beres!', 'Siap!', 'Oke, sudah!', 'Mantap!'];

/** Kalimat konfirmasi setelah perubahan dijalankan, mis. "Beres! Pengeluaran sudah dicatat dan saldo Tunai sudah disesuaikan." */
export function doneLine(parts) {
    const sentence = parts.length > 1 ? `${parts.slice(0, -1).join(', ')} dan ${parts.at(-1)}` : parts[0];
    return `${pick(DONE_OPENERS)} ${capitalize(sentence)}.`;
}
