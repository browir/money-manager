import { ref } from 'vue';

/**
 * Suara asisten: membacakan konfirmasi & semangat dengan suara bawaan perangkat
 * (Web Speech API, gratis, tanpa server). Teks yang dibacakan TIDAK boleh berisi
 * nominal/saldo; server sudah menyaringnya (VoiceAssistant::speakable).
 */
const KEY = 'sisih:speak';
const supported = typeof window !== 'undefined' && 'speechSynthesis' in window;

function read() {
    try {
        return localStorage.getItem(KEY) !== '0';
    } catch {
        return true;
    }
}

const enabled = ref(supported && read());

export function useSpeech() {
    return { enabled, supported };
}

export function toggleSpeech() {
    enabled.value = !enabled.value;
    if (!enabled.value) stopSpeaking();
    try {
        localStorage.setItem(KEY, enabled.value ? '1' : '0');
    } catch {
        // Penyimpanan diblokir: tetap berlaku untuk sesi ini.
    }
}

/**
 * iOS Safari hanya mengizinkan suara yang dimulai dari ketukan pengguna. Panggil ini di
 * dalam handler klik (sebelum menunggu jaringan) agar ucapan berikutnya tidak diblokir.
 */
export function primeSpeech() {
    if (!supported || !enabled.value) return;
    const silent = new SpeechSynthesisUtterance('');
    silent.volume = 0;
    window.speechSynthesis.speak(silent);
}

export function speak(text) {
    if (!supported || !enabled.value || !text) return;
    const synth = window.speechSynthesis;
    synth.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'id-ID';
    const voice = synth.getVoices().find((v) => v.lang?.toLowerCase().replace('_', '-').startsWith('id'));
    if (voice) utterance.voice = voice;
    utterance.rate = 1.03;
    synth.speak(utterance);
}

export function stopSpeaking() {
    if (supported) window.speechSynthesis.cancel();
}
