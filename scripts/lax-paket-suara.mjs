#!/usr/bin/env node
/**
 * Paket suara LAX: membuat audio untuk semua kalimat tetap LAX (resources/js/lib/laxPhrases.js)
 * dengan suara Gemini (sama dengan suara natural di aplikasi), lalu menyimpannya di
 * public/voice/<suara>/ agar ikut ter-deploy ke Vercel. Aplikasi memutar kalimat itu dari berkas,
 * jadi TIDAK memakai kuota saat dipakai; kuota hanya terpakai sekali saat membuat paket.
 *
 * Dua mesin:
 *  - gemini (bawaan): suara utama, sama dengan suara natural di aplikasi. Butuh GEMINI_API_KEY di .env.
 *      node scripts/lax-paket-suara.mjs --nick=Wira [--voice=Fola] [--jeda=21]
 *      --jeda: detik antar permintaan (tier gratis membatasi permintaan/menit; --jeda=1 bila billing aktif)
 *  - edge: paket CADANGAN dengan Edge TTS (github.com/rany2/edge-tts, `pip install edge-tts`), tanpa kunci
 *    & kuota; dipakai aplikasi bila suara Gemini tidak tersedia. Memakai layanan "Read Aloud" Edge secara
 *    tidak resmi (bisa saja diblokir kelak; paket yang sudah jadi tetap berfungsi).
 *      node scripts/lax-paket-suara.mjs --mesin=edge --nick=Wira [--voice=Gadis --edge-voice=id-ID-GadisNeural] [--python=python]
 *  --ulang: buat ulang semua kalimat
 *  --hanya-konversi: ubah berkas WAV lama di paket menjadi MP3 tanpa memanggil Gemini (tanpa kuota)
 *
 * Audio Gemini disimpan sebagai MP3 mono 64 kbps (±10× lebih kecil daripada WAV) lewat
 * @breezystack/lamejs (devDependency, hanya untuk skrip ini).
 *
 * Bisa dilanjutkan: kalimat yang sudah jadi dilewati. Bila kuota harian habis, skrip berhenti
 * rapi; jalankan lagi setelah reset (±16.00 WITA). Selesai → commit public/voice lalu deploy.
 * Ganti nama panggilan / kalimat di laxPhrases.js → jalankan lagi (kalimat lama dibuang).
 */
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Mp3Encoder } from '@breezystack/lamejs';
import { catalog } from '../resources/js/lib/laxPhrases.js';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const args = Object.fromEntries(
    process.argv.slice(2).map((a) => {
        const [k, ...v] = a.replace(/^--/, '').split('=');
        return [k, v.length ? v.join('=') : true];
    }),
);

/* Kunci & model dari .env (tanpa paket tambahan). */
const env = { ...process.env };
if (existsSync(join(root, '.env'))) {
    for (const line of readFileSync(join(root, '.env'), 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z_]+)\s*=\s*"?([^"]*)"?\s*$/);
        if (m && !env[m[1]]) env[m[1]] = m[2];
    }
}
const key = env.GEMINI_API_KEY;
// Semua model suara, berurutan; "model:SuaraBawaan" (dipakai bila suara pilihan tidak ada di model itu).
const models = (env.GEMINI_TTS_MODELS || 'gemini-3.8-flash-tts:Fola,gemini-3.8-flash-lite-tts:Fola,gemini-3.1-flash-tts-preview:Sulafat,gemini-2.5-flash-preview-tts:Sulafat,gemini-2.5-pro-preview-tts:Sulafat')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .map((s) => {
        const [model, voice] = s.split(':');
        return { model, voice: voice || null };
    });
const base = env.GEMINI_BASE_URL || 'https://generativelanguage.googleapis.com'; // bisa diganti untuk pengujian
const engine = args.mesin ?? 'gemini';
const opt = {
    nick: args.nick ?? '',
    voice: args.voice ?? (engine === 'edge' ? 'Gadis' : 'Fola'),
    edgeVoice: args['edge-voice'] ?? 'id-ID-GadisNeural',
    python: args.python ?? env.LAX_PYTHON ?? 'python',
    pause: Number(args.jeda ?? (engine === 'edge' ? 0.3 : 21)),
    again: Boolean(args.ulang),
    convertOnly: Boolean(args['hanya-konversi']),
};
const ext = '.mp3';

if (engine === 'edge') {
    if (spawnSync(opt.python, ['-m', 'edge_tts', '--help'], { encoding: 'utf8' }).status !== 0) {
        console.error(`Modul edge_tts tidak ditemukan untuk "${opt.python}". Pasang dulu: pip install edge-tts`);
        process.exit(1);
    }
} else if (!key && !opt.convertOnly) {
    console.error('GEMINI_API_KEY belum diisi. Tambahkan ke .env (kunci dari aistudio.google.com/app/apikey), lalu jalankan lagi.');
    process.exit(1);
}
if (!opt.nick) console.warn('Peringatan: --nick kosong; sapaan "Tuan <nama>" tidak ikut dibuat.');

// Sama dengan LaxVoice::STYLE. Dikirim lewat speechMetadata, BUKAN di teks (teks dibaca apa adanya).
const STYLE = 'warm, cheerful, respectful personal assistant speaking Indonesian';

/** PCM 16-bit mono (little-endian) → MP3 mono 64 kbps. */
function mp3(pcm, rate) {
    const samples = new Int16Array(pcm.buffer.slice(pcm.byteOffset, pcm.byteOffset + pcm.length - (pcm.length % 2)));
    const encoder = new Mp3Encoder(1, rate, 64);
    const chunks = [];
    for (let i = 0; i < samples.length; i += 1152) {
        const out = encoder.encodeBuffer(samples.subarray(i, i + 1152));
        if (out.length) chunks.push(Buffer.from(out));
    }
    const end = encoder.flush();
    if (end.length) chunks.push(Buffer.from(end));
    return Buffer.concat(chunks);
}

/** Ambil PCM & laju sampel dari berkas WAV (hasil versi skrip sebelumnya). */
function readWav(buffer) {
    const rate = buffer.readUInt32LE(24);
    let offset = 12;
    while (offset + 8 <= buffer.length) {
        const id = buffer.toString('ascii', offset, offset + 4);
        const size = buffer.readUInt32LE(offset + 4);
        // Hanya blok "data" yang audio; blok lain (mis. "C2PA" = tanda keaslian konten AI di akhir
        // berkas Gemini) bukan suara dan akan terdengar seperti derau bila ikut dikodekan.
        if (id === 'data') return { rate, pcm: buffer.subarray(offset + 8, Math.min(buffer.length, offset + 8 + size)) };
        offset += 8 + size + (size % 2); // blok berukuran ganjil diberi 1 byte pengisi
    }
    return null;
}

/** Edge TTS lewat modul Python edge_tts (MP3). */
function synthesizeEdge(text, path) {
    const run = spawnSync(opt.python, ['-m', 'edge_tts', '--voice', opt.edgeVoice, '--text', text, '--write-media', path], { encoding: 'utf8' });
    return run.status === 0 && existsSync(path) && statSync(path).size > 0;
}

let modelIndex = 0;
/** @returns {Promise<{audio: Buffer, model: string, voice: string}|'quota'|null>} */
async function synthesize(text) {
    while (modelIndex < models.length) {
        const { model, voice: fallbackVoice } = models[modelIndex];
        let next = false;
        for (const voice of [...new Set([opt.voice, fallbackVoice].filter(Boolean))]) {
            let voiceRejected = false;
            for (const part of [{ text, speechMetadata: { style: STYLE } }, { text }]) {
                const res = await fetch(`${base}/v1beta/models/${model}:generateContent`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'x-goog-api-key': key },
                    body: JSON.stringify({
                        contents: [{ parts: [part] }],
                        generationConfig: { responseModalities: ['AUDIO'], speechConfig: { voiceConfig: { prebuiltVoiceConfig: { voiceName: voice } } } },
                    }),
                });
                if (res.status === 429 || res.status === 404) {
                    console.log(`  · ${model}: ${res.status === 429 ? 'kuota habis' : 'tidak tersedia'} → model berikutnya`);
                    next = true;
                    break;
                }
                const body = await res.json().catch(() => ({}));
                if (res.status === 400 && body.error?.details?.some((d) => d.reason === 'API_KEY_INVALID')) {
                    console.error('Kunci API Gemini tidak valid.');
                    process.exit(1);
                }
                const audioPart = (body.candidates?.[0]?.content?.parts ?? []).find((p) => p.inlineData?.data);
                if (audioPart) {
                    const raw = Buffer.from(audioPart.inlineData.data, 'base64');
                    // Model 3.x: WAV utuh; model lama: PCM mentah dengan laju di mimeType.
                    const decoded = raw.toString('ascii', 0, 4) === 'RIFF'
                        ? readWav(raw)
                        : { pcm: raw, rate: Number(audioPart.inlineData.mimeType?.match(/rate=(\d+)/)?.[1] ?? 24000) };
                    return { audio: mp3(decoded.pcm, decoded.rate), model, voice };
                }
                if (/voice/i.test(body.error?.message ?? '')) {
                    voiceRejected = true; // suara tidak ada di model ini → suara bawaan model
                    break;
                }
            }
            if (next) break;
            if (!voiceRejected) continue;
        }
        modelIndex++;
    }
    return 'quota';
}

const dir = join(root, 'public', 'voice', opt.voice);
mkdirSync(dir, { recursive: true });
const manifestPath = join(dir, 'manifest.json');
const old = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, 'utf8')) : { phrases: {} };
const phrases = catalog(opt.nick);
const fileFor = (text) => createHash('sha1').update(`${engine}|${opt.voice}|${opt.edgeVoice}|v1|${text}`).digest('hex').slice(0, 16) + ext;
const manifest = { voice: opt.voice, engine, nick: opt.nick, phrases: {}, sources: {} };
const sleep = (s) => new Promise((r) => setTimeout(r, s * 1000));

// Paket lama berformat WAV → ubah ke MP3 tanpa memanggil Gemini.
let converted = 0;
for (const text of phrases) {
    const oldFile = old.phrases?.[text];
    if (!oldFile?.endsWith('.wav') || !existsSync(join(dir, oldFile))) continue;
    const parsed = readWav(readFileSync(join(dir, oldFile)));
    if (!parsed) continue;
    writeFileSync(join(dir, fileFor(text)), mp3(parsed.pcm, parsed.rate));
    manifest.phrases[text] = fileFor(text);
    if (old.sources?.[text]) manifest.sources[text] = old.sources[text];
    converted++;
}
if (converted) console.log(`${converted} berkas WAV diubah ke MP3.`);

let made = 0;
let stopped = '';
for (const [i, text] of opt.convertOnly ? [] : phrases.entries()) {
    const file = fileFor(text);
    const path = join(dir, file);
    if (!opt.again && existsSync(path) && statSync(path).size > 44) {
        manifest.phrases[text] = file;
        continue;
    }
    if (made > 0) await sleep(opt.pause);
    if (engine === 'edge') {
        if (synthesizeEdge(text, path)) {
            manifest.phrases[text] = file;
            made++;
            console.log(`[${i + 1}/${phrases.length}] ${text}`);
        } else {
            console.error(`[${i + 1}/${phrases.length}] GAGAL: ${text}`);
        }
        continue;
    }
    const result = await synthesize(text);
    if (result === 'quota') {
        stopped = 'Kuota SEMUA model suara Gemini habis. Jalankan lagi setelah reset (±16.00 WITA) untuk melanjutkan.';
        break;
    }
    writeFileSync(path, result.audio);
    manifest.phrases[text] = file;
    manifest.sources[text] = `${result.model} · ${result.voice}`;
    made++;
    console.log(`[${i + 1}/${phrases.length}] ${text}  (${result.model} · ${result.voice})`);
}

// Kalimat yang sudah jadi tapi belum terjangkau putaran ini tetap dipertahankan; yang tidak dipakai lagi dibuang.
for (const text of phrases) {
    if (manifest.phrases[text] || !old.phrases?.[text] || !existsSync(join(dir, old.phrases[text]))) continue;
    manifest.phrases[text] = old.phrases[text];
    if (old.sources?.[text]) manifest.sources[text] = old.sources[text];
}
const used = new Set(Object.values(manifest.phrases));
for (const f of readdirSync(dir)) if (/\.(wav|mp3)$/.test(f) && !used.has(f)) rmSync(join(dir, f));

manifest.generated_at = new Date().toISOString();
writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');

// Daftar paket untuk aplikasi: paket Edge ditandai sebagai cadangan.
const indexPath = join(root, 'public', 'voice', 'index.json');
const index = existsSync(indexPath) ? JSON.parse(readFileSync(indexPath, 'utf8')) : { packs: [] };
index.packs = [...index.packs.filter((p) => p.id !== opt.voice), { id: opt.voice, engine, backup: engine === 'edge' }];
writeFileSync(indexPath, JSON.stringify(index, null, 2) + '\n');

const done = Object.keys(manifest.phrases).length;
const size = [...used].reduce((s, f) => s + statSync(join(dir, f)).size, 0);
console.log(`\n${done}/${phrases.length} kalimat siap (${made} baru), ${(size / 1024 / 1024).toFixed(1)} MB di public/voice/${opt.voice}/`);
if (opt.convertOnly) {
    console.log('Mode konversi saja: tidak ada permintaan ke Gemini.');
} else if (stopped) {
    console.log(stopped);
    process.exitCode = 2;
} else {
    console.log('Paket lengkap. Commit public/voice lalu deploy.');
}
