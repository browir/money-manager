import { computed, reactive, ref } from 'vue';
import { route } from 'ziggy-js';
import { pushToast } from '@/composables/useToast';
import { postJson } from '@/lib/http';
import { forSpeech } from '@/lib/lax';

/**
 * Suara LAX. Dua sumber:
 * - "gemini": suara natural dari Gemini TTS lewat server (/asisten/ucap), bila GEMINI_API_KEY ada;
 * - "device": suara bawaan perangkat (Web Speech API), juga cadangan bila Gemini gagal.
 * Teks yang dibacakan TIDAK boleh berisi nominal/saldo (server menyaring jawaban AI,
 * kalimat konfirmasi dibuat di lib/lax.js tanpa angka uang).
 *
 * Pengaturan disimpan per perangkat.
 */
const KEY = 'sisih:speak';
const SETTINGS_KEY = 'sisih:voice';
const supported = typeof window !== 'undefined' && ('speechSynthesis' in window || 'Audio' in window);
const deviceSupported = typeof window !== 'undefined' && 'speechSynthesis' in window;
const SAMPLE = 'Halo, Tuan. Saya LAX, asisten pribadi Anda. Semangat mencatat keuangan hari ini, ya!'; // "LAX" diucapkan lewat forSpeech()
// WAV hening sangat pendek: memutarnya saat ketukan "membuka kunci" pemutar audio di iOS.
const SILENT = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAA=';

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
const speaking = ref(false); // untuk orb LAX & teks melayang
const caption = ref('');
const loading = ref(false); // menunggu audio Gemini
const settings = reactive({
    source: 'gemini',
    cloudVoice: 'Sulafat',
    voiceURI: null,
    rate: 1,
    pitch: 1,
    ...read(SETTINGS_KEY, {}),
});
const cloudVoices = ref(null); // nama suara Gemini dari server; null = tidak tersedia
const cloudVoiceTraits = ref({}); // nama => karakter ("hangat", "ceria", ...)

/* Suara perangkat dimuat asinkron (Chrome mengisi setelah event voiceschanged). */
const voices = ref([]);
if (deviceSupported) {
    const load = () => (voices.value = window.speechSynthesis.getVoices());
    load();
    window.speechSynthesis.addEventListener?.('voiceschanged', load);
}

const isIndonesian = (v) => v.lang?.toLowerCase().replace('_', '-').startsWith('id');
// LAX berkarakter perempuan: utamakan suara perempuan yang dikenal (Gadis di Edge, Damayanti di iOS,
// Google Bahasa Indonesia), lalu suara "Natural"/online yang lebih halus daripada suara lokal lama.
const female = (v) => (/gadis|damayanti|female|wanita|perempuan|google/i.test(v.name) ? 4 : /andika|ardi|\bmale\b|pria/i.test(v.name) ? -4 : 0);
const quality = (v) => female(v) + (/natural|neural|enhanced|premium/i.test(v.name) ? 2 : 0) + (/google|online/i.test(v.name) || !v.localService ? 1 : 0);
const indonesianVoices = computed(() => voices.value.filter(isIndonesian).sort((a, b) => quality(b) - quality(a)));

function currentVoice() {
    return voices.value.find((v) => v.voiceURI === settings.voiceURI) ?? indonesianVoices.value[0] ?? null;
}

const usesCloud = computed(() => settings.source === 'gemini' && Boolean(cloudVoices.value?.length));

export function useSpeech() {
    return { enabled, supported, deviceSupported, settings, indonesianVoices, currentVoice, speaking, caption, loading, cloudVoices, cloudVoiceTraits, usesCloud };
}

/** AppLayout memberi tahu suara Gemini yang tersedia (props features.cloudVoice: { nama: karakter }). */
export function setCloudVoices(map) {
    const list = map ? Object.keys(map) : [];
    cloudVoiceTraits.value = map ?? {};
    cloudVoices.value = list.length ? list : null;
    if (cloudVoices.value && !cloudVoices.value.includes(settings.cloudVoice)) settings.cloudVoice = cloudVoices.value[0];
}

/** Nama suara perangkat yang ramah dibaca: "Microsoft Gadis Online (Natural) - Indonesian (Indonesia)" -> "Gadis (Natural)". */
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
    const { source, cloudVoice, voiceURI, rate, pitch } = settings;
    write(SETTINGS_KEY, { source, cloudVoice, voiceURI, rate, pitch });
}

/* ---- Pemutar ---- */
let audio = null;
let token = 0; // ucapan yang lebih baru membatalkan yang lama
let safety = null;
const cache = new Map(); // "suara|teks" -> object URL audio Gemini (kalimat sapaan sering berulang)
// Simpanan permanen di perangkat (Cache Storage): sapaan & konfirmasi yang sama tidak memakai
// kuota Gemini lagi, termasuk setelah aplikasi ditutup. Dihapus saat keluar (app.js).
export const VOICE_CACHE = 'lax-voice-v1';
const cacheKey = (voice, text) => `/__lax-voice/${encodeURIComponent(voice)}?t=${encodeURIComponent(text)}`;

async function stored(voice, text) {
    try {
        const hit = await (await caches.open(VOICE_CACHE)).match(cacheKey(voice, text));
        return hit ? await hit.blob() : null;
    } catch {
        return null; // Cache Storage tidak tersedia (mis. bukan https)
    }
}
async function store(voice, text, blob) {
    try {
        const box = await caches.open(VOICE_CACHE);
        await box.put(cacheKey(voice, text), new Response(blob, { headers: { 'Content-Type': 'audio/wav' } }));
        const keys = await box.keys();
        for (const old of keys.slice(0, Math.max(0, keys.length - 150))) await box.delete(old); // batasi ±150 kalimat
    } catch {
        // abaikan
    }
}

/* Beri tahu sekali per sesi kenapa LAX memakai suara HP (kuota habis / Gemini bermasalah). */
let warned = false;
function warnFallback(message) {
    if (warned) return;
    warned = true;
    pushToast({ message: `${message} Sementara LAX memakai suara HP.`, tone: 'warn' });
}

function player() {
    audio ??= new Audio();
    return audio;
}

/**
 * Browser HP hanya mengizinkan suara yang dimulai dari ketukan pengguna. Panggil ini di
 * dalam handler klik (sebelum menunggu jaringan) agar ucapan berikutnya tidak diblokir.
 */
export function primeSpeech() {
    if (!enabled.value) return;
    if (deviceSupported) {
        const silent = new SpeechSynthesisUtterance('');
        silent.volume = 0;
        window.speechSynthesis.speak(silent);
    }
    if (usesCloud.value) {
        const a = player();
        a.src = SILENT;
        a.play().catch(() => {});
    }
}

function finish(id, onEnd) {
    if (id !== token) return;
    speaking.value = false;
    caption.value = '';
    loading.value = false;
    clearTimeout(safety);
    onEnd?.();
}

function speakDevice(text, id, onEnd) {
    if (!deviceSupported) return finish(id, onEnd);
    const synth = window.speechSynthesis;
    synth.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    const voice = currentVoice();
    utterance.lang = voice?.lang ?? 'id-ID';
    if (voice) utterance.voice = voice;
    utterance.rate = settings.rate;
    utterance.pitch = settings.pitch;
    utterance.onstart = () => {
        if (id !== token) return;
        speaking.value = true;
        caption.value = text;
    };
    utterance.onend = () => finish(id, onEnd);
    utterance.onerror = () => finish(id, onEnd);
    // Sebagian browser tidak selalu memicu onend: tutup teks melayang paling lambat setelah ini.
    clearTimeout(safety);
    safety = setTimeout(() => finish(id, onEnd), 4000 + (text.length * 90) / settings.rate);
    synth.speak(utterance);
}

async function speakCloud(text, id, onEnd) {
    const voice = settings.cloudVoice;
    const key = `${voice}|${text}`;
    let url = cache.get(key);
    if (!url) {
        loading.value = true;
        try {
            let blob = await stored(voice, text);
            if (!blob) {
                const response = await postJson(route('assistant.speak'), { text, voice });
                if (!response.ok) {
                    const body = await response.json().catch(() => ({}));
                    warnFallback(
                        response.status === 429
                            ? body.message?.includes('Kuota')
                                ? 'Kuota suara Gemini hari ini habis.'
                                : 'Terlalu sering meminta suara.'
                            : body.message || 'Suara Gemini sedang tidak tersedia.',
                    );
                    throw new Error(String(response.status));
                }
                blob = await response.blob();
                store(voice, text, blob);
            }
            url = URL.createObjectURL(blob);
            cache.set(key, url);
            if (cache.size > 40) {
                const [oldKey, oldUrl] = cache.entries().next().value;
                URL.revokeObjectURL(oldUrl);
                cache.delete(oldKey);
            }
        } catch {
            if (id === token) {
                loading.value = false;
                speakDevice(text, id, onEnd); // cadangan: suara perangkat
            }
            return;
        }
    }
    if (id !== token) return;
    loading.value = false;

    const a = player();
    a.src = url;
    a.playbackRate = settings.rate;
    a.onplaying = () => {
        if (id !== token) return;
        speaking.value = true;
        caption.value = text;
    };
    a.onended = () => finish(id, onEnd);
    a.onerror = () => id === token && speakDevice(text, id, onEnd);
    try {
        await a.play();
    } catch {
        // Diblokir aturan autoplay (belum ada ketukan): pakai suara perangkat.
        if (id === token) speakDevice(text, id, onEnd);
    }
}

/** onEnd dipanggil setelah selesai bicara, atau langsung bila suara mati (mis. lanjut mendengarkan). */
export function speak(text, { force = false, onEnd = null } = {}) {
    if (!supported || (!enabled.value && !force) || !text) {
        onEnd?.();
        return;
    }
    text = forSpeech(text);
    stopSpeaking();
    const id = ++token;
    if (usesCloud.value) speakCloud(text, id, onEnd);
    else speakDevice(text, id, onEnd);
}

/** Contoh suara di pengaturan (tetap berbunyi walau suara LAX dimatikan). */
export function previewVoice() {
    speak(SAMPLE, { force: true });
}

export function stopSpeaking() {
    token++;
    speaking.value = false;
    caption.value = '';
    loading.value = false;
    clearTimeout(safety);
    if (deviceSupported) window.speechSynthesis.cancel();
    if (audio) {
        audio.pause();
        audio.onended = audio.onerror = audio.onplaying = null;
    }
}
