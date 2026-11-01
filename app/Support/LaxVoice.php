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

    /** @return string isi berkas WAV */
    public function synthesize(string $text, string $voice): string
    {
        $lastStatus = null;

        foreach (config('services.gemini.tts_models') as $model) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                        'contents' => [['parts' => [[
                            // Arahan gaya + perintah membaca PERSIS: tanpa ini model kadang menambah/mengubah kata,
                            // sehingga ucapan berbeda dari kalimat yang dimaksud aplikasi.
                            'text' => 'Bacakan teks di antara tanda kutip PERSIS kata demi kata, tanpa menambah, mengubah, '
                                .'atau membacakan instruksi ini. Gaya: asisten pribadi perempuan yang anggun, hangat, ceria, '
                                ."dan penuh hormat, dalam bahasa Indonesia.\n\"{$text}\"",
                        ]]]],
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

                continue;
            }
            AiUsage::record('gemini', $model, 'voice', $response->successful() ? 'ok' : 'error', AiUsage::geminiTokens($response->json('usageMetadata')));
            if ($response->failed()) {
                Log::warning('Gemini TTS gagal', ['model' => $model, 'status' => $response->status(), 'message' => $response->json('error.message')]);
                throw new RuntimeException('Suara Gemini sedang bermasalah.');
            }

            $part = collect($response->json('candidates.0.content.parts', []))->first(fn ($p) => isset($p['inlineData']['data']));
            $pcm = $part ? base64_decode($part['inlineData']['data'], true) : false;
            if (! $pcm) {
                throw new RuntimeException('Gemini tidak mengirim audio.');
            }
            // mimeType mis. "audio/L16;codec=pcm;rate=24000"
            preg_match('/rate=(\d+)/', $part['inlineData']['mimeType'] ?? '', $m);

            return self::wav($pcm, (int) ($m[1] ?? 24000));
        }

        throw new RuntimeException($lastStatus === 429 ? 'Kuota suara Gemini habis.' : 'Model suara Gemini tidak tersedia. Periksa GEMINI_TTS_MODELS.');
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
