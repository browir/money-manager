<?php

namespace App\Support;

use App\Models\AiUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Suara LAX dari Gemini (text-to-speech), lebih natural daripada suara bawaan perangkat.
 * Teks yang diucapkan dibuat aplikasi dan tidak berisi nominal kecuali pengguna memintanya.
 * Gemini mengembalikan PCM 16-bit mono; dibungkus jadi WAV agar bisa diputar browser.
 *
 * Semua model suara Gemini dipakai bergantian (permintaan pemilik): kuota dihitung per model,
 * jadi bila satu model habis, model berikutnya dipakai. Tiap model punya suara bawaannya sendiri
 * (dipakai bila suara pilihan tidak ada di model itu); jenis kelamin suara bebas.
 */
class LaxVoice
{
    /** Suara Gemini yang bisa dipilih di Pengaturan → LAX, dengan karakternya (deskripsi Google). */
    public const VOICES = [
        'Fola' => 'jernih & ramah · 3.8',
        'Sulafat' => 'hangat',
        'Achird' => 'ramah',
        'Puck' => 'ceria',
        'Laomedeia' => 'ceria',
        'Sadachbia' => 'bersemangat',
        'Leda' => 'muda',
        'Aoede' => 'santai',
        'Callirrhoe' => 'santai',
        'Zubenelgenubi' => 'kasual',
        'Vindemiatrix' => 'lembut',
        'Achernar' => 'lembut',
        'Despina' => 'halus',
        'Algieba' => 'halus',
        'Zephyr' => 'cerah',
        'Autonoe' => 'cerah',
        'Erinome' => 'jernih',
        'Iapetus' => 'jernih',
        'Kore' => 'tegas',
        'Orus' => 'tegas',
        'Alnilam' => 'tegas',
        'Charon' => 'informatif',
        'Rasalgethi' => 'informatif',
        'Sadaltager' => 'berwawasan',
        'Gacrux' => 'dewasa',
        'Schedar' => 'tenang',
        'Umbriel' => 'santai',
        'Fenrir' => 'antusias',
        'Enceladus' => 'berdesah',
        'Algenib' => 'serak',
        'Pulcherrima' => 'lugas',
    ];

    public const DEFAULT_VOICE = 'Fola';

    public function enabled(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * Daftar model suara berurutan dari config `services.gemini.tts_models`: tiap butir "model" atau
     * "model:SuaraBawaan" (string), atau ['model' => ..., 'voice' => ...].
     *
     * @return list<array{model: string, voice: ?string}>
     */
    public static function models(): array
    {
        return array_values(array_map(function ($entry) {
            if (is_array($entry)) {
                return ['model' => $entry['model'], 'voice' => $entry['voice'] ?? null];
            }
            [$model, $voice] = array_pad(explode(':', (string) $entry, 2), 2, null);

            return ['model' => trim($model), 'voice' => $voice !== null ? trim($voice) : null];
        }, config('services.gemini.tts_models', [])));
    }

    /**
     * @return string isi berkas WAV
     *
     * @throws RuntimeException pesan ramah; kode 429 bila kuota SEMUA model habis
     */
    public function synthesize(string $text, string $voice): string
    {
        $lastStatus = null;

        foreach (self::models() as ['model' => $model, 'voice' => $fallbackVoice]) {
            // Suara pilihan dulu; bila model ini tidak mengenalnya, pakai suara bawaan model.
            foreach (array_values(array_unique(array_filter([$voice, $fallbackVoice]))) as $useVoice) {
                // Gaya bicara lewat speechMetadata (TIDAK di teks: teks dibaca apa adanya, arahan di
                // teks ikut terucap). Model yang tidak mengenal kolom itu (400) → ulangi tanpa gaya.
                foreach ([['text' => $text, 'speechMetadata' => ['style' => self::STYLE]], ['text' => $text]] as $attempt => $request) {
                    try {
                        $response = Http::timeout(30)
                            ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                                'contents' => [['parts' => [$request]]],
                                'generationConfig' => [
                                    'responseModalities' => ['AUDIO'],
                                    'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $useVoice]]],
                                ],
                            ]);
                    } catch (ConnectionException) {
                        throw new RuntimeException('Gagal terhubung ke Gemini.');
                    }

                    if (in_array($response->status(), [404, 429], true)) {
                        $lastStatus = $response->status();
                        AiUsage::record('gemini', $model, 'voice', $response->status() === 429 ? 'rate_limited' : 'error');

                        continue 3; // kuota model ini habis / model tidak ada → model berikutnya
                    }
                    AiUsage::record('gemini', $model, 'voice', $response->successful() ? 'ok' : 'error', AiUsage::geminiTokens($response->json('usageMetadata')));

                    $part = $response->successful()
                        ? collect($response->json('candidates.0.content.parts', []))->first(fn ($p) => isset($p['inlineData']['data']))
                        : null;
                    $pcm = $part ? base64_decode($part['inlineData']['data'], true) : false;

                    if ($pcm) {
                        // Model 3.x mengirim WAV utuh; model lama PCM mentah ("audio/L16;codec=pcm;rate=24000").
                        if (str_starts_with($pcm, 'RIFF')) {
                            return $pcm;
                        }
                        preg_match('/rate=(\d+)/', $part['inlineData']['mimeType'] ?? '', $m);

                        return self::wav($pcm, (int) ($m[1] ?? 24000));
                    }

                    $message = (string) ($response->json('error.message') ?? $response->json('candidates.0.finishReason'));
                    Log::warning('Gemini TTS gagal', [
                        'model' => $model, 'voice' => $useVoice, 'attempt' => $attempt, 'status' => $response->status(),
                        'reason' => collect($response->json('error.details', []))->pluck('reason')->filter()->first(),
                        'message' => $message,
                    ]);
                    if ($response->status() === 403 || $response->json('error.details.0.reason') === 'API_KEY_INVALID') {
                        throw new RuntimeException('Kunci API Gemini tidak valid.');
                    }
                    if (stripos($message, 'voice') !== false) {
                        continue 2; // suara tidak dikenal model ini → coba suara bawaan model
                    }
                }
            }
        }

        throw $lastStatus === 429
            ? new RuntimeException('Kuota suara Gemini hari ini habis.', 429)
            : new RuntimeException('Suara Gemini sedang tidak tersedia.');
    }

    /** Gaya bicara LAX (dikirim lewat speechMetadata.style, bukan dibacakan). */
    public const STYLE = 'warm, cheerful, respectful personal assistant speaking Indonesian';

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
