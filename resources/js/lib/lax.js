import { ref } from 'vue';
import { ACK, DONE_OPENERS, GREETINGS, PROPOSAL, WAKE, forSpeech, honorifics } from './laxPhrases';

/**
 * Identitas asisten suara Sisih: LAX — Ledger Assistant eXtraordinaire.
 * Nama & kepribadian di sini; kalimat tetap di laxPhrases.js (dipakai juga paket suara);
 * prompt server ada di app/Support/VoiceAssistant.php.
 * LAX memanggil pengguna "Tuan", "Tuan <panggilan>", atau "Tuan Muda" (permintaan pemilik).
 * Kalimat yang diucapkan tidak berisi nominal, kecuali pengguna memintanya.
 */
export const LAX = {
    name: 'LAX',
    full: 'Ledger Assistant eXtraordinaire',
    motto: 'Lincah, Akurat, eXtra peduli',
};

export { forSpeech };

export const pick = (list) => list[Math.floor(Math.random() * list.length)];

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

/** Sapaan hormat yang bervariasi: "Tuan", "Tuan Wira" (lebih sering), "Tuan Muda". */
export function honorific(nick) {
    const list = honorifics(nick);
    return pick(nick ? [...list, list[1]] : list);
}

/** Sapaan pembuka sesuai waktu. */
export function greeting(nick) {
    const h = new Date().getHours();
    const slot = h < 11 ? 'pagi' : h < 15 ? 'siang' : h < 19 ? 'sore' : 'malam';
    return pick(GREETINGS[slot])(honorific(nick));
}

/** Jawaban saat dipanggil "Hei LAX" (setelah sapaan pertama sesi ini). */
export const wakeLine = (nick) => pick(WAKE)(honorific(nick));
export const proposalLine = (nick) => pick(PROPOSAL)(honorific(nick));
/** Pembuka singkat saat menjawab pertanyaan. */
export const ackLine = (nick) => pick(ACK)(honorific(nick));

/**
 * Konfirmasi setelah perubahan dijalankan, sebagai potongan terpisah agar tiap potongan bisa
 * diputar dari paket suara: ["Beres, Tuan Wira!", "Pengeluaran sudah dicatat.", "Saldo sudah disesuaikan."]
 */
export const doneLine = (sentences, nick) => [pick(DONE_OPENERS)(honorific(nick)), ...sentences];
