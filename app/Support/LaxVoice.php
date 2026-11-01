<?php

namespace App\Support;

use App\Models\AiUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Suara LAX dari Gemini (text-to-speech), lebih natural daripada suara bawaan perangkat.
 * Teks yang diucapkan dibuat aplikasi dan tidak berisi nominal (lihat lib/lax.js & VoiceAssistant::speakable).
 * Gemini mengembalikan PCM 16-bit mono; dibungkus jadi WAV agar bisa diputar browser.
 */
class LaxVoice
{
    /**
     * Suara perempuan bawaan Gemini yang ditawarkan (LAX berkarakter perempuan), dengan karakternya
     * untuk pilihan di Pengaturan. Karakter mengikuti deskripsi Google; dengarkan contohnya di aplikasi.
     */
    public const VOICES = [
        'Sulafat' => 'hangat',
        'Laomedeia' => 'ceria',
        'Leda' => 'muda',
        'Aoede' => 'santai',
        'Vindemiatrix' => 'lembut',
        'Despina' => 'halus',
        'Zephyr' => 'cerah',
        'Erinome' => 'jernih',
        'Kore' => 'tegas',
        'Gacrux' => 'dewasa',
    ];

    public const DEFAULT_VOICE = 'Sulafat';

    public function enabled(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * @return string isi berkas WAV
     *
     * @throws RuntimeException pesan ramah; kode 429 bila kuota habis (dipakai klien untuk memberi tahu)
     */
    public function synthesize(string $text, string $voice): string
    {
        $lastStatus = null;

        foreach (config('services.gemini.tts_models') as $model) {
            // Coba dengan arahan gaya dulu; bila model menolak (400 / tidak mengirim audio, mis.
            // "model tried to generate text"), ulangi dengan teks polos agar tetap bersuara natural.
            foreach ([self::styled($text), $text] as $attempt => $prompt) {
                try {
                    $response = Http::timeout(30)
                        ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                            'contents' => [['parts' => [['text' => $prompt]]]],
                            'generationConfig' => [
                                'responseModalities' => ['AUDIO'],
                                'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
                            ],
                        ]);
                } catch (ConnectionException) {
                    throw new RuntimeException('Gagal terhubung ke Gemini.');
                }

                if (in_array($response->status(), [404, 429], true)) {
                    $lastStatus = $response->status();
                    AiUsage::record('gemini', $model, 'voice', $response->status() === 429 ? 'rate_limited' : 'error');

                    continue 2; // model berikutnya
                }
                AiUsage::record('gemini', $model, 'voice', $response->successful() ? 'ok' : 'error', AiUsage::geminiTokens($response->json('usageMetadata')));

                $part = $response->successful()
                    ? collect($response->json('candidates.0.content.parts', []))->first(fn ($p) => isset($p['inlineData']['data']))
                    : null;
                $pcm = $part ? base64_decode($part['inlineData']['data'], true) : false;

                if (! $pcm) {
                    Log::warning('Gemini TTS gagal', [
                        'model' => $model, 'attempt' => $attempt, 'status' => $response->status(),
                        'reason' => collect($response->json('error.details', []))->pluck('reason')->filter()->first(),
                        'message' => $response->json('error.message') ?? $response->json('candidates.0.finishReason'),
                    ]);
                    if ($response->status() === 403 || $response->json('error.details.0.reason') === 'API_KEY_INVALID') {
                        throw new RuntimeException('Kunci API Gemini tidak valid.');
                    }

                    continue; // coba teks polos, lalu model berikutnya
                }

                // mimeType mis. "audio/L16;codec=pcm;rate=24000"
                preg_match('/rate=(\d+)/', $part['inlineData']['mimeType'] ?? '', $m);

                return self::wav($pcm, (int) ($m[1] ?? 24000));
            }
        }

        throw $lastStatus === 429
            ? new RuntimeException('Kuota suara Gemini hari ini habis.', 429)
            : new RuntimeException('Suara Gemini sedang tidak tersedia.');
    }

    /** Arahan gaya singkat (format yang dianjurkan Google: "<gaya>: <teks>"). */
    private static function styled(string $text): string
    {
        return "Ucapkan dengan hangat, ceria, dan penuh hormat, seperti asisten pribadi perempuan yang anggun: {$text}";
    }

    /** Bungkus PCM 16-bit mono dengan header WAV (RIFF). */
    public static function wav(string $pcm, int $rate = 24000): string
    {
        $channels = 1;
        $bits = 16;
        $blockAlign = $channels * $bits / 8;

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, $channels, $rate, $rate * $blockAlign, $blockAlign, $bits)
            .'data'.pack('V', strlen($pcm)).$pcm;
    }
}
