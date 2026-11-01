import { ref } from 'vue';

/**
 * Kata panggil "Hei LAX": selama Sisih terbuka di layar (dan fitur ini dinyalakan pengguna),
 * pengenal suara browser mendengarkan terus; begitu terdengar "hei/hai/halo/oke LAX",
 * pendengar berhenti dan handler (AppLayout → buka LAX) dipanggil dengan sisa ucapan,
 * mis. "hei lax catat bakso 25 ribu" → "catat bakso 25 ribu".
 *
 * Batasan browser (dijelaskan juga di Pengaturan):
 * - Tidak ada deteksi kata panggil di perangkat: audio diproses layanan pengenal suara browser
 *   (Chrome/Edge → server Google/Microsoft). Karena itu MATI secara bawaan & ada indikator.
 * - Berhenti saat aplikasi tidak terlihat; iOS/sebagian browser butuh satu ketukan untuk mulai.
 * - Android bisa berbunyi "bip" tiap kali pengenal suara dimulai ulang.
 */
const KEY = 'sisih:wake';
const Recognition = typeof window !== 'undefined' ? window.SpeechRecognition || window.webkitSpeechRecognition : null;
export const wakeSupported = Boolean(Recognition);

// Variasi yang sering terdengar dari pengenal suara bahasa Indonesia.
const WAKE = /(?:^|\s)(?:hei|hey|hai|hay|halo|hallo|hello|he|oke|ok|eh)[\s,.!]*(?:lax|laks|lacks|lex|leks|lak|lack|lux|luks|las)(?![a-z])/i;

function read() {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}

const enabled = ref(wakeSupported && read());
const listening = ref(false); // pendengar kata panggil sedang aktif
const needsTap = ref(false); // browser menolak mulai tanpa ketukan
const blocked = ref(''); // izin mikrofon ditolak dsb.

let rec = null;
let paused = 0;
let mounted = false;
let handler = null;
let restartTimer = null;
let backoff = 300;

export function useWakeWord() {
    return { enabled, listening, needsTap, blocked, supported: wakeSupported };
}

const canRun = () => mounted && enabled.value && paused === 0 && document.visibilityState === 'visible';

function schedule(delay = backoff) {
    clearTimeout(restartTimer);
    restartTimer = setTimeout(start, delay);
}

function stop() {
    clearTimeout(restartTimer);
    if (rec) {
        const r = rec;
        rec = null;
        r.onend = null;
        r.abort();
    }
    listening.value = false;
}

function armTap() {
    needsTap.value = true;
    document.addEventListener(
        'pointerdown',
        () => {
            needsTap.value = false;
            start();
        },
        { once: true, capture: true },
    );
}

function start() {
    if (rec || !canRun()) return;
    const r = new Recognition();
    r.lang = 'id-ID';
    r.continuous = true;
    r.interimResults = true;

    let wakeAt = -1; // indeks hasil tempat kata panggil terdengar
    let fireTimer = null;
    let remainder = '';
    const fire = () => {
        clearTimeout(fireTimer);
        stop();
        navigator.vibrate?.([12, 40, 12]);
        handler?.(remainder.trim());
    };

    r.onstart = () => {
        listening.value = true;
        blocked.value = '';
        backoff = 300;
    };
    r.onresult = (e) => {
        if (wakeAt < 0) {
            for (let i = e.resultIndex; i < e.results.length; i++) {
                if (WAKE.test(e.results[i][0].transcript)) {
                    wakeAt = i;
                    break;
                }
            }
            if (wakeAt < 0) return;
        }
        // Sisa ucapan setelah kata panggil (bisa berlanjut ke hasil berikutnya).
        let heard = '';
        for (let i = wakeAt; i < e.results.length; i++) heard += e.results[i][0].transcript;
        const match = heard.match(WAKE);
        remainder = match ? heard.slice(match.index + match[0].length).replace(/^[\s,.!]+/, '') : heard;
        // Tunggu kalimat selesai: cepat bila sudah final, atau setelah jeda singkat.
        clearTimeout(fireTimer);
        fireTimer = setTimeout(fire, e.results[e.results.length - 1].isFinal ? 250 : 1400);
    };
    r.onerror = (e) => {
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
            // Izin mikrofon ditolak permanen, atau browser hanya mau mulai setelah ketukan (iOS dsb.).
            backoff = 30000;
            navigator.permissions
                ?.query({ name: 'microphone' })
                .then((p) => (p.state === 'denied' ? (blocked.value = 'Izin mikrofon ditolak. Izinkan di pengaturan browser.') : armTap()))
                .catch(armTap) ?? armTap();
        } else if (e.error === 'network') {
            backoff = 10000;
        } else if (e.error === 'audio-capture') {
            blocked.value = 'Mikrofon tidak ditemukan.';
            backoff = 30000;
        }
    };
    r.onend = () => {
        // Browser menghentikan pengenal suara secara berkala: mulai lagi selama masih boleh.
        if (rec !== r) return;
        rec = null;
        listening.value = false;
        if (canRun() && !needsTap.value) schedule();
    };

    rec = r;
    try {
        r.start();
    } catch {
        rec = null;
        armTap();
    }
}

function onVisibility() {
    if (document.visibilityState === 'visible') schedule(400);
    else stop();
}

/** Dipanggil sekali oleh AppLayout. */
export function initWakeWord(onWake) {
    if (!wakeSupported) return () => {};
    handler = onWake;
    mounted = true;
    document.addEventListener('visibilitychange', onVisibility);
    schedule(800);
    return () => {
        mounted = false;
        handler = null;
        document.removeEventListener('visibilitychange', onVisibility);
        stop();
    };
}

/** Nyala/matikan dari Pengaturan (dipanggil dari ketukan, jadi boleh langsung mulai). */
export function setWakeWord(value) {
    enabled.value = value;
    try {
        localStorage.setItem(KEY, value ? '1' : '0');
    } catch {
        // abaikan
    }
    if (value) {
        needsTap.value = false;
        start();
    } else {
        stop();
    }
}

/** Mikrofon dipakai bagian lain (sheet LAX): berhenti dulu, lanjut setelah resumeWakeWord(). */
export function pauseWakeWord() {
    paused++;
    stop();
}

export function resumeWakeWord() {
    paused = Math.max(0, paused - 1);
    if (canRun()) schedule(600);
}
