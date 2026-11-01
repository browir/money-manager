import { computed, reactive, ref } from 'vue';

/**
 * Suara asisten: membacakan konfirmasi & semangat dengan suara bawaan perangkat
 * (Web Speech API, gratis, tanpa server). Teks yang dibacakan TIDAK boleh berisi
 * nominal/saldo; server sudah menyaringnya (VoiceAssistant::speakable).
 *
 * Pilihan suara, kecepatan & nada disimpan per perangkat (daftar suara berbeda di tiap HP).
 */
const KEY = 'sisih:speak';
const SETTINGS_KEY = 'sisih:voice';
const supported = typeof window !== 'undefined' && 'speechSynthesis' in window;
const SAMPLE = 'Halo, aku LAX, asisten buku kasmu. Semangat mencatat keuangan hari ini, ya!';

function read(key, fallback) {
    try {
        const raw = localStorage.getItem(key);
        return raw === null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}
function write(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Penyimpanan diblokir: tetap berlaku untuk sesi ini.
    }
}

const enabled = ref(supported && read(KEY, 1) !== 0);
// Sedang berbicara + teks yang diucapkan (untuk orb LAX & teks melayang).
const speaking = ref(false);
const caption = ref('');
let current = null;
let safety = null;
const settings = reactive({ voiceURI: null, rate: 1, pitch: 1, ...read(SETTINGS_KEY, {}) });

/* Daftar suara dimuat asinkron (Chrome mengisi setelah event voiceschanged). */
const voices = ref([]);
if (supported) {
    const load = () => (voices.value = window.speechSynthesis.getVoices());
    load();
    window.speechSynthesis.addEventListener?.('voiceschanged', load);
}

const isIndonesian = (v) => v.lang?.toLowerCase().replace('_', '-').startsWith('id');
// Suara "Natural"/online (Edge, Chrome, iOS "Enhanced") jauh lebih halus daripada suara lokal lama.
const quality = (v) => (/natural|neural|enhanced|premium/i.test(v.name) ? 2 : 0) + (/google|online/i.test(v.name) || !v.localService ? 1 : 0);

/** Suara Bahasa Indonesia, terbaik dulu. */
const indonesianVoices = computed(() => voices.value.filter(isIndonesian).sort((a, b) => quality(b) - quality(a)));

function currentVoice() {
    return voices.value.find((v) => v.voiceURI === settings.voiceURI) ?? indonesianVoices.value[0] ?? null;
}

export function useSpeech() {
    return { enabled, supported, settings, indonesianVoices, currentVoice, speaking, caption };
}

/** Nama suara yang ramah dibaca: "Microsoft Gadis Online (Natural) - Indonesian (Indonesia)" -> "Gadis (Natural)". */
export function voiceLabel(v) {
    const name = v.name
        .replace(/\s*[-–]\s*(Indonesian|Bahasa Indonesia).*$/i, '')
        .replace(/^Microsoft\s+/i, '')
        .replace(/\s+Online/i, '')
        .trim();
    return name || v.name;
}

export function toggleSpeech() {
    enabled.value = !enabled.value;
    if (!enabled.value) stopSpeaking();
    write(KEY, enabled.value ? 1 : 0);
}

export function saveVoiceSettings(patch) {
    Object.assign(settings, patch);
    write(SETTINGS_KEY, { voiceURI: settings.voiceURI, rate: settings.rate, pitch: settings.pitch });
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

/** onEnd dipanggil setelah selesai bicara, atau langsung bila suara mati (mis. lanjut mendengarkan). */
export function speak(text, { force = false, onEnd = null } = {}) {
    if (!supported || (!enabled.value && !force) || !text) {
        onEnd?.();
        return;
    }
    const synth = window.speechSynthesis;
    synth.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    const voice = currentVoice();
    utterance.lang = voice?.lang ?? 'id-ID';
    if (voice) utterance.voice = voice;
    utterance.rate = settings.rate;
    utterance.pitch = settings.pitch;
    const done = () => {
        if (current !== utterance) return;
        current = null;
        speaking.value = false;
        caption.value = '';
        clearTimeout(safety);
        onEnd?.();
    };
    utterance.onstart = () => {
        speaking.value = true;
        caption.value = text;
    };
    utterance.onend = done;
    utterance.onerror = done;
    current = utterance;
    // Sebagian browser tidak selalu memicu onend: tutup teks melayang paling lambat setelah ini.
    clearTimeout(safety);
    safety = setTimeout(done, 4000 + text.length * 90 / settings.rate);
    synth.speak(utterance);
}

/** Contoh suara di pengaturan (tetap berbunyi walau suara asisten dimatikan). */
export function previewVoice() {
    speak(SAMPLE, { force: true });
}

export function stopSpeaking() {
    if (!supported) return;
    current = null;
    speaking.value = false;
    caption.value = '';
    window.speechSynthesis.cancel();
}
