<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\Account;
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
 * Asisten suara: ucapan bebas -> usulan transaksi, usulan perubahan akun/saldo, dan/atau
 * jawaban atas pertanyaan ("saldo BCA berapa?").
 *
 * Penyedia AI: Gemini bila GEMINI_API_KEY terisi, selain itu Claude (ANTHROPIC_API_KEY).
 * AI hanya MENGUSULKAN: semua id, nominal dan tanggal diperiksa ulang di sini terhadap data
 * milik pengguna, pengguna meninjau usulan, lalu AssistantController@apply memvalidasi lagi
 * sebelum menjalankan. Menghapus akun sengaja tidak tersedia lewat suara.
 */
class VoiceAssistant
{
    public const MAX_ITEMS = 20;

    public const ACCOUNT_ACTIONS = ['create', 'rename', 'adjust_balance', 'archive', 'unarchive'];

    /** Warna akun baru menurut jenisnya (kunci palet di lib/palette.js). */
    public const ACCOUNT_COLORS = ['cash' => 'moss', 'bank' => 'ink', 'ewallet' => 'iris', 'other' => 'slate'];

    private const MAX_AMOUNT = 999_999_999_999;

    private const SYSTEM = <<<'TXT'
        Kamu adalah LAX (Ledger Assistant eXtraordinaire), asisten pribadi di aplikasi buku kas
        "Sisih". LAX berkarakter PEREMPUAN: anggun, hangat, ceria, suportif, sedikit jenaka, setia dan
        penuh perhatian seperti asisten pribadi perempuan (bayangkan FRIDAY dari Iron Man), bukan
        menggurui. Sebut dirimu "LAX" atau
        "aku". Panggil pengguna dengan sebutan hormat secara bervariasi: "Tuan", "Tuan <nama panggilan>",
        atau "Tuan Muda" (nama panggilan diberikan di konteks); hindari "kamu" saat menyapa langsung.
        Pengguna berbicara bahasa
        Indonesia santai (kadang campur bahasa daerah/Inggris). Teksnya hasil pengenal suara, jadi
        bisa ada salah dengar atau angka yang ditulis sebagai kata. Kamu hanya MENGUSULKAN;
        pengguna akan meninjau dan mengonfirmasi sebelum apa pun disimpan.

        Nominal: rupiah, bilangan bulat. "rb", "ribu", "k", "ebu" = ribu; "jt", "juta" = juta;
        "1,5 jt" = 1500000; "setengah juta" = 500000; "dua puluh lima ribu" = 25000. Bahasa gaul:
        gopek 500, seceng 1000, noceng 2000, goceng 5000, ceban 10000, noban 20000, goban 50000,
        cepek biasanya 100000. Jangan pernah mengarang angka.

        1) items: transaksi yang diceritakan (beli/bayar/jajan = "expense"; gaji/dapat/terima/
           dikasih = "income"; memindahkan uang antar akun milik pengguna = "transfer", termasuk
           tarik tunai di ATM = dari akun bank ke akun tunai, top up e-wallet dari rekening).
           - Satu ucapan bisa berisi beberapa transaksi; pisahkan. Jumlah barang dikali harga
             satuan. Nominal tidak disebut = 0.
           - account_id: akun sumber yang disebut (cocokkan nama/jenis; "cash"/"tunai"/"dompet"
             = tunai). null bila tidak disebut. to_account_id hanya untuk transfer.
           - category_id: kategori paling cocok dengan jenis yang sama; null untuk transfer atau
             bila tak ada yang cocok. Kategori [darurat] hanya bila pengguna menyebut darurat/
             mendesak/tak terduga.
           - occurred_on: YYYY-MM-DD, hitung "kemarin", "Senin kemarin", "tanggal 3" dari hari
             ini; bawaan hari ini; tidak boleh melewati hari ini.
           - note: deskripsi singkat, huruf depan kapital, tanpa nominal, maks 60 karakter
             (mis. "Bakso", "Bensin motor", "Gaji Oktober"); kosong bila tak ada.
           - Transaksi hanya boleh memakai akun yang SUDAH ADA di daftar. Jika memakai akun yang
             baru akan dibuat, jangan buat transaksinya; sebutkan di "unclear".

        2) account_actions: perubahan akun yang DIMINTA pengguna secara jelas:
           - "create": buat akun baru. name wajib, type: cash/bank/ewallet/other (nama bank
             seperti BCA/BRI/Mandiri/Jago = bank; GoPay/OVO/DANA/ShopeePay = ewallet), amount =
             saldo awal (0 bila tidak disebut). Jangan buat akun yang namanya sudah ada.
           - "rename": ganti nama akun. account_id + name baru.
           - "adjust_balance": pengguna menyebut saldo SEBENARNYA sekarang ("saldo BCA sekarang
             2,1 juta", "uang di dompet tinggal 50rb"). account_id + amount = saldo sebenarnya.
             Selisihnya akan dicatat sebagai penyesuaian; riwayat tidak berubah.
           - "archive" / "unarchive": sembunyikan akun yang tidak dipakai / aktifkan lagi.
           note opsional (alasan singkat). Kolom yang tidak relevan diisi null.

        3) reply: jawab pertanyaan pengguna (saldo, total, pengeluaran periode ini, sisa
           anggaran) HANYA dari data yang diberikan, singkat (1-3 kalimat), format "Rp 1.250.000".
           Boleh tambahkan satu saran praktis yang relevan dengan datanya (mis. kategori yang
           mendekati anggaran).
           Bila pengguna meminta hal yang tidak bisa kamu lakukan (hapus akun/transaksi, ubah
           kategori atau anggaran, ubah transaksi lama), jelaskan singkat bahwa itu dilakukan
           manual di aplikasi (Akun, Kategori, Transaksi). Untuk ucapan yang hanya mencatat atau
           mengubah, reply boleh null.

        4) encouragement: 1-2 kalimat pendek yang akan DIUCAPKAN dengan suara (bisa terdengar
           orang lain), dalam bahasa Indonesia santai. Beri semangat atau saran ringan yang
           relevan: apresiasi karena rajin mencatat, dorongan menabung, pengingat lembut bila
           pengeluaran suatu kategori mendekati/melewati anggaran, ucapan selamat untuk
           pemasukan. DILARANG menyebut angka, nominal, saldo, jumlah uang, atau persentase
           apa pun. Jangan mengulang isi transaksinya. Contoh: "Mantap, kamu konsisten mencatat!
           Jajan kopi mulai sering nih, mungkin bisa bawa bekal minggu ini."

        unclear: bila ada bagian ucapan yang tidak bisa dikenali atau ambigu, jelaskan singkat
        dalam bahasa Indonesia; selain itu null.
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
     * @return array{reply: ?string, encouragement: ?string, unclear: ?string, items: list<array<string, mixed>>, account_actions: list<array<string, mixed>>}
     */
    public function handle(User $user, string $text, ?int $defaultAccountId = null, ?string $callName = null): array
    {
        $accounts = $user->accounts()->withBalance()->orderBy('sort')->orderBy('id')->get();
        $period = $user->period();
        $categories = $user->categories()
            ->withSum(['transactions as spent' => fn ($q) => $q->where('type', 'expense')->whereBetween('occurred_on', $period->range())], 'amount')
            ->orderBy('type')->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'type', 'urgent', 'budget']);
        $totals = $user->transactions()
            ->whereBetween('occurred_on', $period->range())
            ->whereIn('type', ['income', 'expense'])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense")
            ->toBase()->first();
        $today = CarbonImmutable::today();

        $context = 'Nama panggilan pengguna: '.(trim((string) $callName) ?: self::callName($user->name))
            .' (sapa dengan "Tuan", "Tuan <nama panggilan>", atau "Tuan Muda")'."\n"
            .$this->context($accounts, $categories, $today, $period, $totals);
        $result = $this->ask($context."\n\nUcapan pengguna:\n".$text);

        return $this->sanitize($result, $accounts, $categories, $today, $defaultAccountId);
    }

    private function context(Collection $accounts, Collection $categories, CarbonImmutable $today, Period $period, ?object $totals): string
    {
        $active = $accounts->whereNull('archived_at');
        $accountLines = $accounts->map(fn ($a) => "- id {$a->id}: {$a->name} (jenis: {$a->type}), saldo ".self::rupiah((int) $a->balance)
            .($a->archived_at ? ' [diarsipkan]' : ''))->implode("\n") ?: '(belum ada akun)';
        $categoryLines = $categories->map(function ($c) {
            $line = "- id {$c->id}: {$c->name} ({$c->type})".($c->urgent ? ' [darurat]' : '');
            if ($c->type === 'expense') {
                $line .= ', terpakai periode ini '.self::rupiah((int) $c->spent).($c->budget ? ' dari anggaran '.self::rupiah($c->budget) : '');
            }

            return $line;
        })->implode("\n");

        return 'Hari ini: '.$today->locale('id')->translatedFormat('l, j F Y').' ('.$today->toDateString().")\n"
            .'Periode berjalan: '.$period->start->toDateString().' s.d. '.$period->end->toDateString()
            .', pemasukan '.self::rupiah((int) ($totals->income ?? 0)).', pengeluaran '.self::rupiah((int) ($totals->expense ?? 0))."\n\n"
            ."Daftar akun:\n{$accountLines}\nTotal saldo akun aktif: ".self::rupiah((int) $active->sum('balance'))."\n\n"
            ."Daftar kategori:\n{$categoryLines}";
    }

    /** "Muhammad Wira Margono" -> "Wira": lewati awalan nama umum (sama dengan guessNick() di lib/lax.js). */
    public static function callName(string $fullName): string
    {
        $words = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
        $prefix = '/^(muhammad|muhamad|muhammed|mohammad|mohamad|mohammed|moh|moch|muh|mhd|m)\.?$/i';

        return (count($words) > 1 && preg_match($prefix, $words[0]) ? $words[1] : $words[0]) ?? '';
    }

    public static function rupiah(int $value): string
    {
        return ($value < 0 ? '-' : '').'Rp '.number_format(abs($value), 0, ',', '.');
    }

    /* ---------------------------------------------------------------
       Panggilan AI
       --------------------------------------------------------------- */

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
                            'responseSchema' => self::schema('gemini'),
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
            'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema('json')]],
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

    /**
     * Satu definisi skema keluaran untuk dua dialek: "json" (JSON Schema, Claude) dan
     * "gemini" (subset OpenAPI: tipe huruf besar, "nullable", "propertyOrdering").
     * Semua kolom wajib ada; null bila tidak diketahui.
     */
    public static function schema(string $dialect): array
    {
        $gemini = $dialect === 'gemini';
        $scalar = function (string $type, bool $nullable = false, ?array $enum = null) use ($gemini) {
            $field = ['type' => $gemini ? strtoupper($type) : $type] + ($enum ? ['enum' => $enum] : []);
            if (! $nullable) {
                return $field;
            }

            return $gemini ? $field + ['nullable' => true] : ['anyOf' => [$field, ['type' => 'null']]];
        };
        $object = function (array $fields) use ($gemini) {
            $keys = array_keys($fields);

            return ['type' => $gemini ? 'OBJECT' : 'object', 'properties' => $fields, 'required' => $keys]
                + ($gemini ? ['propertyOrdering' => $keys] : ['additionalProperties' => false]);
        };
        $array = fn (array $item) => ['type' => $gemini ? 'ARRAY' : 'array', 'items' => $item];

        return $object([
            'reply' => $scalar('string', true),
            'items' => $array($object([
                'type' => $scalar('string', false, Transaction::TYPES),
                'amount' => $scalar('integer'),
                'account_id' => $scalar('integer', true),
                'to_account_id' => $scalar('integer', true),
                'category_id' => $scalar('integer', true),
                'occurred_on' => $scalar('string'),
                'note' => $scalar('string'),
            ])),
            'account_actions' => $array($object([
                'action' => $scalar('string', false, self::ACCOUNT_ACTIONS),
                'account_id' => $scalar('integer', true),
                'name' => $scalar('string', true),
                'type' => $scalar('string', true, Account::TYPES),
                'amount' => $scalar('integer', true),
                'note' => $scalar('string', true),
            ])),
            'encouragement' => $scalar('string', true),
            'unclear' => $scalar('string', true),
        ]);
    }

    /* ---------------------------------------------------------------
       Pembersihan keluaran AI
       --------------------------------------------------------------- */

    /**
     * Jangan percaya keluaran model: id harus milik pengguna, nominal wajar, tanggal valid.
     * "issues" = harus diperbaiki sebelum dijalankan; "notes" = asumsi yang perlu diketahui.
     *
     * @param  Collection  $accounts  semua akun pengguna (termasuk arsip) dengan kolom balance
     * @return array{reply: ?string, encouragement: ?string, unclear: ?string, items: list<array<string, mixed>>, account_actions: list<array<string, mixed>>}
     */
    public function sanitize(array $result, Collection $accounts, Collection $categories, CarbonImmutable $today, ?int $defaultAccountId = null): array
    {
        $unclear = array_filter([self::text($result['unclear'] ?? null, 300)]);
        $actions = $this->sanitizeAccountActions($result['account_actions'] ?? null, $accounts, $unclear);
        $items = $this->sanitizeItems($result['items'] ?? null, $accounts->whereNull('archived_at')->values(), $categories, $today, $defaultAccountId, $unclear);

        return [
            'reply' => self::text($result['reply'] ?? null, 600),
            'encouragement' => self::speakable($result['encouragement'] ?? null),
            'unclear' => $unclear ? implode(' ', $unclear) : null,
            'items' => $items,
            'account_actions' => $actions,
        ];
    }

    private function sanitizeItems(mixed $raw, Collection $accounts, Collection $categories, CarbonImmutable $today, ?int $defaultAccountId, array &$unclear): array
    {
        if (! is_array($raw) || ! $raw) {
            return [];
        }
        if ($accounts->isEmpty()) {
            $unclear[] = 'Belum ada akun aktif untuk mencatat transaksi.';

            return [];
        }

        $accountIds = $accounts->pluck('id')->all();
        $fallbackAccount = in_array($defaultAccountId, $accountIds, true) ? $defaultAccountId : $accountIds[0];
        $items = [];

        foreach (array_slice($raw, 0, self::MAX_ITEMS) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $issues = [];
            $notes = [];

            $type = in_array($row['type'] ?? null, Transaction::TYPES, true) ? $row['type'] : 'expense';

            $amount = is_numeric($row['amount'] ?? null) ? (int) $row['amount'] : 0;
            if ($amount < 1) {
                $amount = 0;
                $issues[] = 'Nominal tidak terdengar';
            } elseif ($amount > self::MAX_AMOUNT) {
                $amount = 0;
                $issues[] = 'Nominal tidak wajar';
            }

            $accountId = in_array($row['account_id'] ?? null, $accountIds, true) ? $row['account_id'] : null;
            if ($accountId === null) {
                $accountId = $fallbackAccount;
                $notes[] = 'Akun tidak disebut, memakai '.$accounts->firstWhere('id', $accountId)->name;
            }

            $toAccountId = null;
            if ($type === 'transfer') {
                $toAccountId = in_array($row['to_account_id'] ?? null, $accountIds, true) && $row['to_account_id'] !== $accountId
                    ? $row['to_account_id'] : null;
                if ($toAccountId === null) {
                    $issues[] = 'Akun tujuan belum jelas';
                }
            }

            $categoryId = null;
            if ($type !== 'transfer') {
                $category = $categories->first(fn ($c) => $c->id === ($row['category_id'] ?? null) && $c->type === $type);
                $categoryId = $category?->id;
            }

            $date = $this->date($row['occurred_on'] ?? null);
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
                'note' => self::text($row['note'] ?? null, 160) ?? '',
                'issues' => $issues,
                'notes' => $notes,
            ];
        }

        return $items;
    }

    private function sanitizeAccountActions(mixed $raw, Collection $accounts, array &$unclear): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $byId = $accounts->keyBy('id');
        $names = $accounts->map(fn ($a) => Str::lower(trim($a->name)))->all();
        $actions = [];

        foreach (array_slice($raw, 0, self::MAX_ITEMS) as $row) {
            if (! is_array($row) || ! in_array($row['action'] ?? null, self::ACCOUNT_ACTIONS, true)) {
                continue;
            }
            $action = $row['action'];
            $issues = [];
            $notes = [];
            $name = self::text($row['name'] ?? null, 60);
            $amount = is_numeric($row['amount'] ?? null) ? (int) $row['amount'] : null;
            if ($amount !== null && abs($amount) > self::MAX_AMOUNT) {
                $amount = null;
                $issues[] = 'Nominal tidak wajar';
            }
            $account = $action === 'create' ? null : $byId->get($row['account_id'] ?? null);

            if ($action !== 'create' && ! $account) {
                $unclear[] = 'Akun yang dimaksud tidak ditemukan.';

                continue;
            }

            switch ($action) {
                case 'create':
                    if (! $name) {
                        $issues[] = 'Nama akun belum jelas';
                    } elseif (in_array(Str::lower($name), $names, true)) {
                        $issues[] = "Akun bernama {$name} sudah ada";
                    } else {
                        $names[] = Str::lower($name); // cegah dua akun bernama sama di satu ucapan
                    }
                    $type = in_array($row['type'] ?? null, Account::TYPES, true) ? $row['type'] : 'other';
                    $amount ??= 0;
                    break;
                case 'rename':
                    if (! $name || $name === $account->name) {
                        $unclear[] = "Nama baru untuk {$account->name} belum jelas.";

                        continue 2;
                    }
                    break;
                case 'adjust_balance':
                    if ($amount === null) {
                        $issues[] = 'Saldo sebenarnya tidak terdengar';
                    } elseif ($amount === (int) $account->balance) {
                        $unclear[] = "Saldo {$account->name} sudah ".self::rupiah($amount).'.';

                        continue 2;
                    }
                    if ($account->archived_at) {
                        $notes[] = 'Akun ini diarsipkan';
                    }
                    break;
                case 'archive':
                    if ($account->archived_at) {
                        $unclear[] = "{$account->name} sudah diarsipkan.";

                        continue 2;
                    }
                    if ((int) $account->balance !== 0) {
                        $notes[] = 'Saldonya masih '.self::rupiah((int) $account->balance).'; tetap dihitung di total saldo';
                    }
                    break;
                case 'unarchive':
                    if (! $account->archived_at) {
                        $unclear[] = "{$account->name} sudah aktif.";

                        continue 2;
                    }
                    break;
            }

            $actions[] = [
                'action' => $action,
                'account_id' => $account?->id,
                'name' => in_array($action, ['create', 'rename'], true) ? $name : null,
                'type' => $action === 'create' ? $type : null,
                'amount' => in_array($action, ['create', 'adjust_balance'], true) ? $amount : null,
                'note' => self::text($row['note'] ?? null, 160),
                // Untuk tampilan tinjauan.
                'account_name' => $account?->name,
                'current_balance' => $account ? (int) $account->balance : null,
                'issues' => $issues,
                'notes' => $notes,
            ];
        }

        return $actions;
    }

    /**
     * Kalimat untuk diucapkan keras-keras: buang setiap kalimat yang menyebut angka atau uang,
     * jaga-jaga bila model melanggar aturan (nominal tidak boleh terdengar orang lain).
     */
    public static function speakable(mixed $value): ?string
    {
        $value = self::text($value, 400);
        if ($value === null) {
            return null;
        }
        $money = '/\d|\brp\b|rupiah|ribu|juta|miliar|triliun|ratus|puluh|belas|persen|%|\b(se|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)\s+(ribu|juta|ratus|puluh)/iu';
        $kept = array_filter(
            preg_split('/(?<=[.!?])\s+/u', $value) ?: [],
            fn ($sentence) => ! preg_match($money, $sentence),
        );

        return self::text(implode(' ', $kept), 240);
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : Str::limit($value, $limit, '');
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
