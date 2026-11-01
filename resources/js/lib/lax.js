import { ref } from 'vue';

/**
 * Identitas asisten suara Sisih: LAX — Ledger Assistant eXtraordinaire.
 * Nama, sapaan & kepribadian di sini; prompt server ada di app/Support/VoiceAssistant.php.
 * LAX memanggil pengguna "Tuan", "Tuan <panggilan>", atau "Tuan Muda" (permintaan pemilik).
 * Semua kalimat yang diucapkan TIDAK boleh berisi nominal.
 */
export const LAX = {
    name: 'LAX',
    full: 'Ledger Assistant eXtraordinaire',
    motto: 'Lincah, Akurat, eXtra peduli',
    // Cara mengucapkan nama: huruf kapital "LAX" dieja L-A-X oleh mesin suara, jadi tulis bunyinya.
    spoken: 'Laks',
};

/** Siapkan teks untuk dibacakan: "LAX" diucapkan sebagai satu kata, bukan dieja. */
export function forSpeech(text) {
    return text.replace(/\bLAX\b/gi, LAX.spoken);
}

export const pick = (list) => list[Math.floor(Math.random() * list.length)];

const capitalize = (s) => s.charAt(0).toUpperCase() + s.slice(1);

/* ---- Nama panggilan (per perangkat; kosong = ditebak dari nama akun) ---- */
const NICK_KEY = 'sisih:lax-nick';
function readNick() {
    try {
        return localStorage.getItem(NICK_KEY) ?? '';
    } catch {
        return '';
    }
}
export const laxNick = ref(readNick());

export function setLaxNick(value) {
    laxNick.value = value.trim().slice(0, 30);
    try {
        localStorage.setItem(NICK_KEY, laxNick.value);
    } catch {
        // abaikan
    }
}

/** "Muhammad Wira Margono" -> "Wira": lewati awalan nama yang umum seperti Muhammad/M./Moh. Sama dengan VoiceAssistant::callName(). */
export function guessNick(fullName = '') {
    const words = fullName.trim().split(/\s+/).filter(Boolean);
    const prefix = /^(muhammad|muhamad|muhammed|mohammad|mohamad|mohammed|moh|moch|muh|mhd|m)\.?$/i;
    return (words.length > 1 && prefix.test(words[0]) ? words[1] : words[0]) ?? '';
}

export function callName(fullName) {
    return laxNick.value || guessNick(fullName);
}

/** Sapaan hormat yang bervariasi: "Tuan", "Tuan Wira", "Tuan Muda". */
export function honorific(nick) {
    return pick(nick ? ['Tuan', `Tuan ${nick}`, `Tuan ${nick}`, 'Tuan Muda'] : ['Tuan', 'Tuan Muda']);
}

/** Sapaan pembuka sesuai waktu. */
export function greeting(nick) {
    const t = honorific(nick);
    const h = new Date().getHours();
    if (h < 11) return pick([`Selamat pagi, ${t}. LAX siap membantu hari ini.`, `Pagi, ${t}! Mari mulai hari dengan rapi.`]);
    if (h < 15) return pick([`Selamat siang, ${t}. Ada yang perlu LAX catat?`, `Siang, ${t}! Habis jajan siang? Sebutkan saja, LAX catat.`]);
    if (h < 19) return pick([`Selamat sore, ${t}. Mau catat sesuatu atau cek keuangan?`, `Sore, ${t}! LAX siap mendengarkan.`]);
    return pick([`Selamat malam, ${t}. Mari tutup hari dengan mencatat transaksi.`, `Malam, ${t}. Ada yang terlewat dicatat hari ini?`]);
}

/** Jawaban saat dipanggil "Hei LAX" (setelah sapaan pertama sesi ini). */
export function wakeLine(nick) {
    const t = honorific(nick);
    return pick([`Ya, ${t}? LAX di sini.`, `Siap, ${t}. Ada yang bisa LAX bantu?`, `Untuk Anda, ${t}, LAX selalu siap.`, `Silakan, ${t}.`]);
}

export function proposalLine(nick) {
    const t = honorific(nick);
    return pick([`LAX sudah siapkan, ${t}. Mohon dicek dulu sebelum dijalankan.`, `Ini usulan LAX, ${t}. Silakan periksa, lalu tekan jalankan.`]);
}

/** Pembuka singkat saat menjawab pertanyaan (jawaban berangka hanya tampil di layar, tidak diucapkan). */
export function ackLine(nick) {
    const t = honorific(nick);
    return pick([`Baik, ${t}.`, `Tentu, ${t}.`, `Siap, ${t}.`]);
}

/** Konfirmasi setelah perubahan dijalankan, mis. "Beres, Tuan Wira! Pengeluaran sudah dicatat dan saldo Tunai sudah disesuaikan." */
export function doneLine(parts, nick) {
    const sentence = parts.length > 1 ? `${parts.slice(0, -1).join(', ')} dan ${parts.at(-1)}` : parts[0];
    return `${pick(['Beres', 'Siap', 'Laksanakan', 'Sudah beres'])}, ${honorific(nick)}! ${capitalize(sentence)}.`;
}
