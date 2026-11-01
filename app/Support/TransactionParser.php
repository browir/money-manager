<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mengurai ucapan bebas ("tadi makan bakso goceng pake cash") jadi draf transaksi.
 *
 * Penyedia AI: Gemini bila GEMINI_API_KEY terisi, selain itu Claude (ANTHROPIC_API_KEY).
 * AI hanya MENGUSULKAN: semua id, nominal dan tanggal diperiksa ulang di sini
 * terhadap data milik pengguna, lalu pengguna meninjau draf sebelum disimpan
 * (penyimpanan lewat TransactionController@storeMany, divalidasi ulang lagi).
 */
class TransactionParser
{
    public const MAX_ITEMS = 20;

    private const MAX_AMOUNT = 999_999_999_999;

    private const SYSTEM = <<<'TXT'
        Kamu membantu mencatat keuangan pribadi. Pengguna berbicara bahasa Indonesia santai
        (kadang campur bahasa daerah/Inggris) tentang pemasukan dan pengeluarannya. Teksnya
        hasil pengenal suara, jadi bisa ada salah dengar atau angka yang ditulis sebagai kata.
        Ubah ucapan itu menjadi daftar transaksi.

        Aturan:
        - Satu ucapan bisa berisi beberapa transaksi; pisahkan masing-masing.
        - amount: rupiah, bilangan bulat. "rb", "ribu", "k", "ebu" = ribu; "jt", "juta" = juta;
          "1,5 jt" = 1500000; "setengah juta" = 500000; "dua puluh lima ribu" = 25000.
          Bahasa gaul: gopek 500, seceng 1000, noceng 2000, goceng 5000, ceban 10000,
          noban 20000, goban 50000, cepek biasanya 100000. Jumlah barang dikali harga satuan
          ("kopi dua, masing-masing 15 ribu" = 30000). Jika nominal tidak disebut, isi 0;
          jangan mengarang angka.
        - type: "expense" untuk beli/bayar/jajan/habis/keluar; "income" untuk gaji/dapat/terima/
          dikasih/dibayar/cair; "transfer" untuk memindahkan uang antar akun milik pengguna
          sendiri (tarik tunai di ATM = transfer dari akun bank ke akun tunai; top up e-wallet
          dari rekening = transfer).
        - account_id: akun sumber yang disebut (cocokkan dengan nama atau jenis di daftar akun:
          "cash"/"tunai"/"dompet" = tunai, nama bank, nama e-wallet). null bila tidak disebut.
          Untuk transfer, to_account_id = akun tujuan; untuk selain transfer selalu null.
        - category_id: kategori paling cocok dengan jenis yang sama (expense/income) dari
          daftar kategori, null untuk transfer atau bila tidak ada yang cocok. Kategori
          darurat hanya bila pengguna menyebut darurat/mendesak/tak terduga.
        - occurred_on: tanggal YYYY-MM-DD. Hitung kata waktu ("kemarin", "tadi pagi",
          "Senin kemarin", "tanggal 3") dari tanggal hari ini yang diberikan. Bawaan: hari ini.
          Jangan pernah melewati hari ini.
        - note: deskripsi singkat dalam bahasa Indonesia dari apa yang dibeli/diterima, huruf
          depan kapital, tanpa nominal, maksimal 60 karakter (mis. "Bakso", "Bensin motor",
          "Gaji Oktober"). Kosongkan bila tidak ada keterangan selain kategori.
        - unclear: bila tidak ada transaksi yang bisa dikenali, jelaskan singkat dalam bahasa
          Indonesia apa yang kurang; bila ada yang ambigu, sebutkan singkat. Selain itu null.
        TXT;

    /** "gemini", "claude", atau null bila belum ada kunci API sama sekali. */
    public function provider(): ?string
    {
        return match (true) {
            filled(config('services.gemini.key')) => 'gemini',
            filled(config('services.anthropic.key')) => 'claude',
            default => null,
        };
    }

    public function enabled(): bool
    {
        return $this->provider() !== null;
    }

    /** Nama untuk ditampilkan di aplikasi. */
    public function providerLabel(): ?string
    {
        return ['gemini' => 'Gemini', 'claude' => 'Claude'][$this->provider()] ?? null;
    }

    /**
     * @return array{items: list<array<string, mixed>>, unclear: ?string}
     */
    public function parse(User $user, string $text, ?int $defaultAccountId = null): array
    {
        $accounts = $user->accounts()->whereNull('archived_at')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'type']);
        $categories = $user->categories()->orderBy('type')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'type', 'urgent']);
        $today = CarbonImmutable::today();

        if ($accounts->isEmpty()) {
            throw new RuntimeException('Belum ada akun aktif. Buat akun dulu di halaman Akun.');
        }

        $result = $this->ask($this->context($accounts, $categories, $today)."\n\nUcapan pengguna:\n".$text);

        return $this->sanitize($result, $accounts, $categories, $today, $defaultAccountId);
    }

    private function context(Collection $accounts, Collection $categories, CarbonImmutable $today): string
    {
        $accountLines = $accounts->map(fn ($a) => "- id {$a->id}: {$a->name} (jenis: {$a->type})")->implode("\n");
        $categoryLines = $categories->map(fn ($c) => "- id {$c->id}: {$c->name} ({$c->type})".($c->urgent ? ' [darurat]' : ''))->implode("\n");

        return 'Hari ini: '.$today->locale('id')->translatedFormat('l, j F Y').' ('.$today->toDateString().")\n\n"
            ."Daftar akun:\n{$accountLines}\n\nDaftar kategori:\n{$categoryLines}";
    }

    /** @return array<string, mixed> */
    protected function ask(string $prompt): array
    {
        return $this->provider() === 'gemini' ? $this->askGemini($prompt) : $this->askClaude($prompt);
    }

    /**
     * Gemini lewat REST (tidak ada SDK PHP resmi). Model dicoba berurutan: 404 (model tidak
     * tersedia untuk kunci ini) atau 429 (kuota model habis) -> model berikutnya. Tanpa jeda
     * tunggu panjang: fungsi Vercel punya batas waktu.
     *
     * @return array<string, mixed>
     */
    private function askGemini(string $prompt): array
    {
        $lastStatus = null;

        foreach (config('services.gemini.models') as $model) {
            try {
                $response = Http::timeout(25)
                    ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                        'systemInstruction' => ['parts' => [['text' => self::SYSTEM]]],
                        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'temperature' => 0,
                            'responseMimeType' => 'application/json',
                            'responseSchema' => self::geminiSchema(),
                        ],
                    ]);
            } catch (ConnectionException) {
                throw new RuntimeException('Gagal terhubung ke Gemini. Periksa koneksi lalu coba lagi.');
            }

            if (in_array($response->status(), [404, 429], true)) {
                $lastStatus = $response->status();

                continue;
            }

            if ($response->failed()) {
                $reason = collect($response->json('error.details', []))->pluck('reason')->filter()->first();
                Log::warning('Gemini API gagal', ['model' => $model, 'status' => $response->status(), 'reason' => $reason, 'message' => $response->json('error.message')]);

                throw new RuntimeException(in_array($reason, ['API_KEY_INVALID', 'API_KEY_SERVICE_BLOCKED'], true) || $response->status() === 403
                    ? 'Kunci API Gemini tidak valid. Periksa GEMINI_API_KEY di server.'
                    : 'Gemini sedang bermasalah. Coba lagi nanti.');
            }

            $candidate = $response->json('candidates.0') ?? [];
            $finish = $candidate['finishReason'] ?? null;
            if ($finish === 'MAX_TOKENS') {
                throw new RuntimeException('Ucapan terlalu panjang. Coba pecah jadi beberapa bagian.');
            }
            if ($response->json('promptFeedback.blockReason') || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true)) {
                throw new RuntimeException('Gemini tidak bisa memproses ucapan ini. Coba ucapkan dengan kalimat lain.');
            }

            $text = collect($candidate['content']['parts'] ?? [])->reject(fn ($p) => $p['thought'] ?? false)->pluck('text')->implode('');
            // Jaga-jaga bila balasan tetap dibungkus pagar markdown ```json.
            $data = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text))), true);
            if (is_array($data)) {
                return $data;
            }

            throw new RuntimeException('Jawaban Gemini tidak bisa dibaca. Coba lagi.');
        }

        throw new RuntimeException($lastStatus === 429
            ? 'Kuota Gemini habis untuk saat ini. Coba lagi nanti, atau catat manual dulu.'
            : 'Model Gemini tidak tersedia untuk kunci ini. Periksa GEMINI_MODELS di server.');
    }

    /** @return array<string, mixed> */
    private function askClaude(string $prompt): array
    {
        $client = new Client(
            apiKey: config('services.anthropic.key'),
            requestOptions: ['timeout' => 40, 'maxRetries' => 1],
        );

        $model = config('services.anthropic.model');
        $params = [
            'model' => $model,
            'maxTokens' => 16000,
            'system' => self::SYSTEM,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            // Ekstraksi sederhana: effort rendah = cepat; skema menjamin JSON yang bisa dibaca.
            'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ];
        // Bila model utama menolak, API mencoba ulang dengan model cadangan dalam panggilan yang sama.
        // Hanya Opus/Fable/Sonnet 5.5 yang mendukung; model lain (mis. Haiku) dikirim tanpa ini.
        if ($this->supportsFallback($model)) {
            $params['betas'] = ['server-side-fallback-2026-07-01'];
            $params['fallbacks'] = 'default';
        }

        try {
            $message = $client->beta->messages->create(...$params);
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new RuntimeException('Kunci API Claude tidak valid. Periksa ANTHROPIC_API_KEY di server.');
        } catch (RateLimitException) {
            throw new RuntimeException('Terlalu banyak permintaan ke Claude. Coba lagi sebentar.');
        } catch (APIStatusException $e) {
            Log::warning('Claude API gagal', ['status' => $e->status, 'type' => $e->type?->value, 'message' => $e->getMessage()]);
            throw new RuntimeException($e->type?->value === 'billing_error'
                ? 'Kredit API Claude habis. Isi saldo di console.anthropic.com.'
                : 'Claude sedang bermasalah. Coba lagi nanti.');
        } catch (APIConnectionException) {
            throw new RuntimeException('Gagal terhubung ke Claude. Periksa koneksi lalu coba lagi.');
        }

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude tidak bisa memproses ucapan ini. Coba ucapkan dengan kalimat lain.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('Ucapan terlalu panjang. Coba pecah jadi beberapa bagian.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        throw new RuntimeException('Jawaban Claude tidak bisa dibaca. Coba lagi.');
    }

    private function supportsFallback(string $model): bool
    {
        return str_starts_with($model, 'claude-opus-5') || str_starts_with($model, 'claude-fable-5') || $model === 'claude-sonnet-5-5';
    }

    /** Skema yang sama dalam format Gemini (subset OpenAPI: tipe huruf besar, "nullable"). */
    private static function geminiSchema(): array
    {
        $fields = [
            'type' => ['type' => 'STRING', 'enum' => Transaction::TYPES],
            'amount' => ['type' => 'INTEGER'],
            'account_id' => ['type' => 'INTEGER', 'nullable' => true],
            'to_account_id' => ['type' => 'INTEGER', 'nullable' => true],
            'category_id' => ['type' => 'INTEGER', 'nullable' => true],
            'occurred_on' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
            'note' => ['type' => 'STRING'],
        ];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => $fields,
                        'required' => array_keys($fields),
                        'propertyOrdering' => array_keys($fields),
                    ],
                ],
                'unclear' => ['type' => 'STRING', 'nullable' => true],
            ],
            'required' => ['items', 'unclear'],
            'propertyOrdering' => ['items', 'unclear'],
        ];
    }

    /** Skema keluaran terstruktur: semua kolom wajib ada, null bila tidak diketahui. */
    private static function schema(): array
    {
        $nullableInt = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => Transaction::TYPES],
                            'amount' => ['type' => 'integer'],
                            'account_id' => $nullableInt,
                            'to_account_id' => $nullableInt,
                            'category_id' => $nullableInt,
                            'occurred_on' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'amount', 'account_id', 'to_account_id', 'category_id', 'occurred_on', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
                'unclear' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            ],
            'required' => ['items', 'unclear'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Jangan percaya keluaran model: id harus milik pengguna, nominal wajar, tanggal valid.
     * "issues" = harus diperbaiki sebelum disimpan; "notes" = asumsi yang perlu diketahui pengguna.
     *
     * @return array{items: list<array<string, mixed>>, unclear: ?string}
     */
    public function sanitize(array $result, Collection $accounts, Collection $categories, CarbonImmutable $today, ?int $defaultAccountId = null): array
    {
        $accountIds = $accounts->pluck('id')->all();
        $fallbackAccount = in_array($defaultAccountId, $accountIds, true) ? $defaultAccountId : $accountIds[0];
        $items = [];

        foreach (array_slice(is_array($result['items'] ?? null) ? $result['items'] : [], 0, self::MAX_ITEMS) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $issues = [];
            $notes = [];

            $type = in_array($raw['type'] ?? null, Transaction::TYPES, true) ? $raw['type'] : 'expense';

            $amount = is_numeric($raw['amount'] ?? null) ? (int) $raw['amount'] : 0;
            if ($amount < 1) {
                $amount = 0;
                $issues[] = 'Nominal tidak terdengar';
            } elseif ($amount > self::MAX_AMOUNT) {
                $amount = 0;
                $issues[] = 'Nominal tidak wajar';
            }

            $accountId = in_array($raw['account_id'] ?? null, $accountIds, true) ? $raw['account_id'] : null;
            if ($accountId === null) {
                $accountId = $fallbackAccount;
                $notes[] = 'Akun tidak disebut, memakai '.$accounts->firstWhere('id', $accountId)->name;
            }

            $toAccountId = null;
            if ($type === 'transfer') {
                $toAccountId = in_array($raw['to_account_id'] ?? null, $accountIds, true) && $raw['to_account_id'] !== $accountId
                    ? $raw['to_account_id'] : null;
                if ($toAccountId === null) {
                    $issues[] = 'Akun tujuan belum jelas';
                }
            }

            $categoryId = null;
            if ($type !== 'transfer') {
                $category = $categories->first(fn ($c) => $c->id === ($raw['category_id'] ?? null) && $c->type === $type);
                $categoryId = $category?->id;
            }

            $date = $this->date($raw['occurred_on'] ?? null);
            if ($date === null) {
                $date = $today;
                $notes[] = 'Tanggal tidak jelas, memakai hari ini';
            } elseif ($date->greaterThan($today)) {
                $date = $today;
                $notes[] = 'Tanggal di masa depan diganti hari ini';
            } elseif ($date->lessThan($today->subYear())) {
                $notes[] = 'Tanggal lebih dari setahun lalu, periksa lagi';
            }

            $items[] = [
                'type' => $type,
                'amount' => $amount,
                'account_id' => $accountId,
                'to_account_id' => $toAccountId,
                'category_id' => $categoryId,
                'occurred_on' => $date->toDateString(),
                'note' => Str::limit(trim(is_string($raw['note'] ?? null) ? $raw['note'] : ''), 160, ''),
                'issues' => $issues,
                'notes' => $notes,
            ];
        }

        $unclear = is_string($result['unclear'] ?? null) ? Str::limit(trim($result['unclear']), 300) : null;

        return ['items' => $items, 'unclear' => $unclear ?: null];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->toDateString() === $value ? $date : null;
    }
}
