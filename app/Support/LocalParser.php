<?php

namespace App\Support;

use App\Models\AssistantExample;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengurai lokal LAX, cadangan saat semua model AI gagal (docs/rencana-pengurai-lokal.md).
 * Keluarannya sebentuk jawaban AI supaya lewat VoiceAssistant::sanitize() yang sama. Aturan
 * utamanya: tidak pernah menebak nominal. Bila ragu, nominal dikosongkan atau ditanyakan.
 */
class LocalParser
{
    private const UNITS = ['ratus ribu' => 100_000, 'ratus' => 100, 'ribu' => 1000, 'rb' => 1000, 'ebu' => 1000, 'k' => 1000, 'jt' => 1_000_000, 'juta' => 1_000_000, 'miliar' => 1_000_000_000];

    private const SLANG = ['gopek' => 500, 'seceng' => 1000, 'noceng' => 2000, 'goceng' => 5000, 'ceban' => 10_000, 'noban' => 20_000, 'goban' => 50_000, 'cepek' => 100_000];

    private const DIGITS = ['satu' => 1, 'dua' => 2, 'tiga' => 3, 'empat' => 4, 'lima' => 5, 'enam' => 6, 'tujuh' => 7, 'delapan' => 8, 'sembilan' => 9];

    private const NUMBER_WORDS = 'setengah|koma|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh|sebelas|seratus|seribu|sejuta|belas|puluh|ratus|ribu|juta|miliar';

    /** Angka tanpa satuan di bawah ini dianggap jumlah barang ("3 kopi"), bukan rupiah. */
    private const MIN_BARE_AMOUNT = 500;

    private const WEEKDAYS = ['minggu' => 0, 'senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, 'sabtu' => 6];

    private const MONTHS = ['januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12];

    private const CONNECTORS = '/(,\s+|;|\bdan\b|\bterus\b|\blalu\b|\bsama\b|\bkemudian\b|\bserta\b|\bplus\b)/u';

    private const TRANSFER = '/\b(transfer|tf|pindah(?:kan)?|(?:tarik|ambil) (?:tunai|uang|duit)|(?:tarik|ambil)\b.*\batm|top ?up|isi saldo)\b/u';

    private const WITHDRAW = '/\b((?:tarik|ambil) (?:tunai|uang|duit)|(?:tarik|ambil)\b.*\batm)\b/u';

    private const TOP_UP = '/\b(top ?up|isi saldo)\b/u';

    private const EXPENSE = '/\b(beli|bayar|jajan|belanja|makan|minum|isi)\b/u';

    private const INCOME = '/\b(gaji|gajian|dapat|dapet|terima|dikasih|bonus|thr|komisi|pemasukan|cashback|refund|jual|masuk)\b/u';

    private const RECORD = '/\b(catat|beli|bayar|jajan|belanja|gaji|gajian|dapat|dapet|terima|dikasih|bonus|thr|transfer|tf|pindah(?:kan)?|tarik tunai|top ?up)\b/u';

    private const UNIT_PRICE = '/(@|\bmasing-masing\b|\bmasing2\b|\bper (?:biji|porsi|buah|pcs|bungkus)\b|\bsatunya\b|\bsatuan\b|\btotal\b)/u';

    /** Dua nominal yang bukan dua transaksi: "bayar 50 ribu kembalian 5 ribu". */
    private const CHANGE = '/\b(kembali|kembalian|diskon|potongan)\b/u';

    private const UNSUPPORTED = '/\b((?:buat|bikin|tambah) akun|(?:ganti|ubah) nama|arsip\w*|aktifkan|hapus|tinggal)\b|saldo\b.*\b(sekarang|jadi)\b/u';

    private const PERIOD_OTHER = '/\b(kemarin|lalu|minggu|tahun|hari ini|tadi)\b/u';

    private const LEADING_VERBS = '/^(?:(?:beli|bayar|jajan|dapat|dapet|terima)\s+)+/u';

    private const FILLERS = '/\b(tolong|catat(?:kan)?|barusan|mau|saya|aku|gue|gw|udah|sudah|dong|ya|nih|sih|kok|tadi(?: pagi| siang| sore| malam)?|seharga|sebesar|harga|rupiah|perak|rp|masuk|uang|duit|atm)\b/u';

    private const PREPOSITIONS = '(?:(?:pakai|pake|dari|ke|lewat|via|dengan|di|akun)\s+)*';

    private const STOPWORDS = ['tolong', 'catat', 'catatkan', 'barusan', 'saya', 'aku', 'gue', 'gw', 'udah', 'sudah', 'dong', 'ya', 'nih', 'sih', 'kok', 'tadi', 'beli', 'bayar', 'jajan', 'buat', 'untuk', 'di', 'ke', 'dari', 'dan', 'yang', 'pakai', 'pake', 'lewat', 'via', 'dengan', 'seharga', 'sebesar', 'harga', 'rupiah', 'perak', 'rp', 'ribu', 'rb', 'juta', 'jt', 'kemarin', 'hari', 'ini', 'sama'];

    /** Keyakinan minimum sebelum kategori hasil belajar dipakai. */
    private const MIN_CONFIDENCE = 0.6;

    /** Kecil, supaya kata yang baru muncul sekali sudah berarti saat datanya masih sedikit. */
    private const SMOOTHING = 0.1;

    /**
     * @param  list<array{text: string, category_id: int}>  $training
     * @return array<string, mixed> sebentuk jawaban AI
     */
    public function parse(string $text, Collection $accounts, Collection $categories, CarbonImmutable $today, ?object $totals = null, array $training = []): array
    {
        $accounts = $accounts->whereNull('archived_at')->values();
        $s = $this->normalize($text);

        if (preg_match('/\bberapa\b|\?|^(cek|lihat|sisa|sebutkan|bacakan|ucapkan|kasih tahu|beri tahu)\b/u', $s)) {
            return $this->answer($s, $accounts, $categories, $totals);
        }
        if (preg_match(self::UNSUPPORTED, $s)) {
            return $this->result(unclear: 'Mode hemat: LAX belum bisa mengubah akun atau saldo. Silakan lakukan di menu Akun.');
        }

        $clauses = $this->clauses($s, $accounts, $today);
        $hasAmount = collect($clauses)->contains(fn ($c) => $this->amounts($this->extractDate($c, $today)[1]) !== []);
        if (! $hasAmount && ! preg_match(self::RECORD, $s)) {
            return $this->result(unclear: 'Mode hemat: LAX belum menangkap maksudnya. Coba sebutkan apa dan berapa.');
        }

        // Tanggal dan akun yang disebut sekali berlaku untuk semua ("bakso 25rb dan kopi 10rb pakai tunai, kemarin").
        $sharedDate = collect($clauses)->map(fn ($c) => $this->extractDate($c, $today)[0])->first(fn ($d) => $d !== null);
        $mentioned = collect($this->mentions($s, $accounts))->pluck('id')->unique()->values();
        $sharedAccount = $mentioned->count() === 1 ? $mentioned->first() : null;

        $classifier = $this->classifier($training);
        $unclear = [];
        $items = [];
        foreach ($clauses as $clause) {
            $items[] = $this->item($clause, $accounts, $categories, $today, $sharedDate, $sharedAccount, $classifier, $unclear);
        }

        return $this->result(items: $items, unclear: $unclear ? implode(' ', array_unique($unclear)) : null);
    }

    /**
     * Bahan belajar kategori: catatan transaksi + ucapan yang sudah dikonfirmasi pengguna.
     *
     * @return list<array{text: string, category_id: int}>
     */
    public static function training(User $user, int $limit = 1000): array
    {
        $rows = $user->transactions()
            ->whereNotNull('category_id')->whereNotNull('note')->where('note', '!=', '')
            ->latest('id')->limit($limit)->get(['note', 'category_id'])
            ->map(fn ($t) => ['text' => $t->note, 'category_id' => $t->category_id])->all();

        try {
            $examples = AssistantExample::where('user_id', $user->id)->whereNotNull('applied_at')->latest('id')->limit($limit)->get(['text', 'final']);
        } catch (Throwable) {
            return $rows; // migrasi belum dijalankan: riwayat transaksi saja sudah cukup
        }

        foreach ($examples as $example) {
            $items = $example->final['items'] ?? [];
            if (count($items) === 1 && ! empty($items[0]['category_id'])) {
                $rows[] = ['text' => $example->text, 'category_id' => (int) $items[0]['category_id']];
            }
        }

        return $rows;
    }

    private function result(?string $reply = null, ?string $unclear = null, array $items = []): array
    {
        return [
            'reply' => $reply,
            'encouragement' => null,
            // sanitize() tetap memeriksa ucapannya memang meminta nominal dibacakan.
            'speak_amounts' => $reply !== null,
            'unclear' => $unclear,
            'items' => $items,
            'account_actions' => [],
        ];
    }

    private function normalize(string $text): string
    {
        $s = Str::lower(trim($text));
        $s = str_replace(["jum'at", 'jum at'], 'jumat', $s);
        $s = preg_replace('/^(?:h[ae]i\s+)?lax\b[\s,]*/u', '', $s);

        return trim(preg_replace('/\s+/u', ' ', $s), " \t.!");
    }

    /* ---------------------------------------------------------------
       Satu ucapan -> beberapa klausa, tiap klausa satu transaksi
       --------------------------------------------------------------- */

    /** Klausa tanpa nominal digabung ke berikutnya ("nasi dan ayam 30rb" = satu transaksi). */
    private function clauses(string $s, Collection $accounts, CarbonImmutable $today): array
    {
        $parts = preg_split(self::CONNECTORS, $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        $clauses = [];
        $pending = '';

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $pending = $pending === '' ? '' : $pending.' '.trim($part);

                continue;
            }
            $joined = trim($pending.' '.$part);
            if ($this->amounts($this->extractDate($joined, $today)[1]) !== []) {
                array_push($clauses, ...$this->splitAtAmounts($joined, $accounts, $today));
                $pending = '';
            } else {
                $pending = $joined;
            }
        }

        $pending = trim(preg_replace('/[,;]\s*$/u', '', $pending));
        if ($pending === '') {
            return $clauses;
        }
        // Sisa tanpa nominal: keterangan klausa sebelumnya, atau transaksi yang nominalnya belum disebut.
        if ($clauses && $this->note($this->extractDate($pending, $today)[1], $accounts) === '') {
            $clauses[array_key_last($clauses)] .= ' '.$pending;
        } else {
            $clauses[] = $pending;
        }

        return $clauses;
    }

    /** "bakso 25 ribu kopi 10 ribu": pengenal suara sering tanpa koma, jadi pisah di tiap nominal. */
    private function splitAtAmounts(string $clause, Collection $accounts, CarbonImmutable $today): array
    {
        [, $rest, $datePhrase] = $this->extractDate($clause, $today);
        $amounts = $this->amounts($rest);
        if (count($amounts) < 2 || preg_match(self::CHANGE, $rest)) {
            return [$clause];
        }

        $pieces = [];
        $from = 0;
        foreach ($amounts as $i => $amount) {
            // Nominal tanpa nama barang ("bensin 20rb 30rb") tidak jelas maksudnya.
            if ($i > 0 && $this->note(substr($rest, $from, $amount['start'] - $from), $accounts) === '') {
                return [$clause];
            }
            $end = $i === array_key_last($amounts) ? strlen($rest) : $amount['end'];
            $pieces[] = trim(substr($rest, $from, $end - $from));
            $from = $end;
        }
        if ($datePhrase !== null) {
            $pieces[0] = $datePhrase.' '.$pieces[0];
        }

        return $pieces;
    }

    private function item(string $clause, Collection $accounts, Collection $categories, CarbonImmutable $today, ?string $sharedDate, ?int $sharedAccount, array $classifier, array &$unclear): array
    {
        [$date, $rest] = $this->extractDate($clause, $today);
        $amounts = $this->amounts($rest);

        $amount = 0;
        if (count($amounts) > 1) {
            $unclear[] = 'Ada lebih dari satu nominal dalam satu transaksi. Periksa nominalnya.';
        } elseif ($amounts && ($amounts[0]['ambiguous'] ?? false)) {
            $unclear[] = 'Nominalnya bisa dibaca dua cara. Isi nominal lengkapnya.';
        } elseif ($amounts && preg_match(self::UNIT_PRICE, $rest)) {
            $unclear[] = 'Harga satuan belum bisa dihitung di mode hemat. Isi totalnya.';
        } elseif ($amounts) {
            $amount = $amounts[0]['value'];
        }

        $words = $this->withoutAmounts($rest, $amounts);
        $type = $this->type($rest, $categories, $classifier);
        $note = $this->note($words, $accounts);
        $accountId = null;
        $toAccountId = null;
        $categoryId = null;

        if ($type === 'transfer') {
            [$accountId, $toAccountId] = $this->transferAccounts($rest, $accounts);
            $note = $note !== '' ? $note : (preg_match(self::WITHDRAW, $rest) ? 'Tarik tunai' : (preg_match(self::TOP_UP, $rest) ? 'Top up' : 'Transfer'));
        } else {
            $accountId = $this->mentions($rest, $accounts)[0]['id'] ?? $sharedAccount;
            $categoryId = $this->category($words, $type, $categories, $classifier);
        }

        return [
            'type' => $type,
            'amount' => $amount,
            'account_id' => $accountId,
            'to_account_id' => $toAccountId,
            'category_id' => $categoryId,
            'occurred_on' => $date ?? $sharedDate ?? $today->toDateString(),
            'note' => Str::limit(Str::ucfirst($note), 60, ''),
        ];
    }

    private function type(string $s, Collection $categories, array $classifier): string
    {
        if (preg_match(self::TRANSFER, $s)) {
            return 'transfer';
        }
        if (preg_match(self::EXPENSE, $s)) {
            return 'expense';
        }
        if (preg_match(self::INCOME, $s)) {
            return 'income';
        }

        // Tanpa kata kunci: ikuti jenis kategori hasil belajar, bawaannya pengeluaran.
        $learned = $this->classify($this->tokens($s), $categories, $classifier);

        return $learned?->type === 'income' ? 'income' : 'expense';
    }

    /** Kata yang tersisa untuk catatan, setelah kata pengisi, akun, dan kata kerja dibuang. */
    private function note(string $s, Collection $accounts): string
    {
        $s = $this->withoutAmounts($s, $this->amounts($s));
        // Sebelum akun dibuang: "tarik tunai" jangan tersisa "tarik".
        $s = preg_replace(self::TRANSFER, ' ', $s);
        foreach ($this->accountPatterns($accounts) as [$pattern]) {
            $s = preg_replace('/\b'.self::PREPOSITIONS.'(?:'.$pattern.')\b/u', ' ', $s);
        }
        $s = preg_replace(self::FILLERS, ' ', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s), " ,;.");
        $s = preg_replace(self::LEADING_VERBS, '', $s.' ');
        $s = preg_replace('/\b(?:pakai|pake|dari|ke|lewat|via|dengan|di)\s*$/u', '', trim($s));

        return trim(preg_replace('/\s+,/u', ',', $s), " ,;.");
    }

    /* ---------------------------------------------------------------
       Nominal
       --------------------------------------------------------------- */

    /** @return list<array{value: int, unit: int, start: int, end: int}> */
    private function amounts(string $s): array
    {
        $found = [];

        // Tiap jenis yang ketemu dikosongkan, supaya "25 ribu" tidak terbaca lagi sebagai "ribu".
        $take = function (string $pattern, callable $read) use (&$s, &$found) {
            preg_match_all($pattern, $s, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
            foreach ($matches as $m) {
                $amount = $read($m);
                if ($amount !== null) {
                    $found[] = [...$amount, 'start' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
                    $s = substr_replace($s, str_repeat(' ', strlen($m[0][0])), $m[0][1], strlen($m[0][0]));
                }
            }
        };

        $slang = implode('|', array_keys(self::SLANG));
        $take("/(?<![\\w.,])(?:(\\d+)\\s+)?({$slang})\\b/u", fn ($m) => [
            'value' => (int) ($m[1][0] ?? 1) * self::SLANG[$m[2][0]],
            'unit' => 1,
        ]);

        $units = implode('|', array_map(fn ($u) => str_replace(' ', '\s*', $u), array_keys(self::UNITS)));
        $take("/(?<![\\w.,])(rp\\.?\\s*)?(\\d+(?:[.,]\\d+)*)(?:\\s*({$units}))?(?![\\w])/u", function ($m) {
            $unit = $m[3][0] === null ? 1 : self::UNITS[preg_replace('/\s+/u', ' ', str_replace('ratusribu', 'ratus ribu', $m[3][0]))];
            $value = (int) round($this->number($m[2][0]) * $unit);

            return $unit === 1 && $m[1][0] === null && $value < self::MIN_BARE_AMOUNT ? null : ['value' => $value, 'unit' => $unit];
        });

        $words = self::NUMBER_WORDS;
        $take("/\\b(?:{$words})(?:\\s+(?:{$words}))*\\b/u", fn ($m) => $this->words($m[0][0]));

        usort($found, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $this->mergeCompound($found);
    }

    /** Titik/koma ribuan ("25.000") dibedakan dari desimal ("1,5"). */
    private function number(string $raw): float
    {
        return preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $raw)
            ? (float) preg_replace('/[.,]/', '', $raw)
            : (float) str_replace(',', '.', $raw);
    }

    /** "dua puluh lima ribu" -> 25000. Null bila terlalu kecil untuk disebut nominal ("dua bakso"). */
    private function words(string $phrase): ?array
    {
        $total = 0.0;
        $hundreds = 0;
        $digit = 0;
        $fraction = 0.0;
        $unit = 1;
        $afterComma = false;

        foreach (preg_split('/\s+/u', $phrase) as $word) {
            if (isset(self::DIGITS[$word])) {
                $afterComma ? $fraction = self::DIGITS[$word] / 10 : $digit = self::DIGITS[$word];
                $afterComma = false;

                continue;
            }
            switch ($word) {
                case 'koma': $afterComma = true; break;
                case 'setengah': $fraction = 0.5; break;
                case 'sepuluh': $hundreds += 10; break;
                case 'sebelas': $hundreds += 11; break;
                case 'seratus': $hundreds += 100; break;
                case 'seribu': $total += 1000; $unit = 1000; break;
                case 'sejuta': $total += 1_000_000; $unit = 1_000_000; break;
                case 'belas': $hundreds += $digit + 10; $digit = 0; break;
                case 'puluh': $hundreds += $digit * 10; $digit = 0; break;
                case 'ratus': $hundreds += $digit * 100; $digit = 0; break;
                default: // ribu, juta, miliar
                    $unit = self::UNITS[$word];
                    $total += ($hundreds + $digit + $fraction ?: 1) * $unit;
                    $hundreds = $digit = 0;
                    $fraction = 0.0;
            }
        }

        $rest = $hundreds + $digit + $fraction;
        $value = (int) round($total + $rest);
        // "sejuta lima ratus" bisa berarti 1.000.500 atau 1.500.000.
        $ambiguous = $total >= 1_000_000 && $rest > 0 && $rest < 1000;
        if ($rest > 0) {
            $unit = 1;
        }

        return $value < self::MIN_BARE_AMOUNT ? null : ['value' => $value, 'unit' => $unit, 'ambiguous' => $ambiguous];
    }

    /** "2 juta 500 ribu" terbaca dua potong: gabungkan bila yang kedua lebih kecil dari satuan yang pertama. */
    private function mergeCompound(array $found): array
    {
        $merged = [];
        foreach ($found as $amount) {
            $last = $merged === [] ? null : $merged[array_key_last($merged)];
            if ($last && $last['unit'] > 1 && $amount['value'] < $last['unit'] && $amount['start'] - $last['end'] <= 1) {
                $merged[array_key_last($merged)] = [
                    'value' => $last['value'] + $amount['value'],
                    'unit' => $amount['unit'],
                    'start' => $last['start'],
                    'end' => $amount['end'],
                    // "1 juta 500" bisa berarti 1.000.500 atau 1.500.000.
                    'ambiguous' => $last['unit'] >= 1_000_000 && $amount['unit'] === 1 && $amount['value'] < 1000,
                ];
            } else {
                $merged[] = $amount;
            }
        }

        return $merged;
    }

    private function withoutAmounts(string $s, array $amounts): string
    {
        foreach (array_reverse($amounts) as $amount) {
            $s = substr_replace($s, ' ', $amount['start'], $amount['end'] - $amount['start']);
        }

        return $s;
    }

    /* ---------------------------------------------------------------
       Tanggal
       --------------------------------------------------------------- */

    /**
     * @return array{0: ?string, 1: string, 2: ?string} tanggal (null = tidak disebut, '' = tidak valid), sisa kalimat, frasa tanggal
     */
    private function extractDate(string $s, CarbonImmutable $today): array
    {
        $days = implode('|', array_keys(self::WEEKDAYS));
        $months = implode('|', array_keys(self::MONTHS));

        $rules = [
            '/\bhari ini\b/u' => fn () => $today,
            '/\bkemarin (?:lusa|dulu)\b/u' => fn () => $today->subDays(2),
            '/\b(?:seminggu|minggu) lalu\b/u' => fn () => $today->subDays(7),
            '/\b(\d+|dua|tiga|empat|lima|enam|tujuh) hari (?:yang )?lalu\b/u' => fn ($m) => $today->subDays((int) (self::DIGITS[$m[1]] ?? $m[1])),
            "/\\b({$days}) (?:kemarin|lalu)\\b/u" => fn ($m) => $this->lastWeekday($today, $m[1], true),
            '/\b(senin|selasa|rabu|kamis|jumat|sabtu)\b/u' => fn ($m) => $this->lastWeekday($today, $m[1], false),
            '/\bkemarin\b/u' => fn () => $today->subDay(),
            "/\\b(?:tanggal|tgl) (\\d{1,2})(?: ({$months}))?(?: (\\d{4}))?\\b/u" => fn ($m) => $this->dayOfMonth($today, (int) $m[1], $m[2] ?? '', $m[3] ?? ''),
        ];

        foreach ($rules as $pattern => $read) {
            if (preg_match($pattern, $s, $m)) {
                $date = $read($m);

                return [$date?->toDateString() ?? '', trim(preg_replace($pattern, ' ', $s, 1)), $m[0]];
            }
        }

        return [null, $s, null];
    }

    private function lastWeekday(CarbonImmutable $today, string $name, bool $beforeToday): CarbonImmutable
    {
        $diff = ($today->dayOfWeek - self::WEEKDAYS[$name] + 7) % 7;

        return $today->subDays($diff === 0 && $beforeToday ? 7 : $diff);
    }

    /** Tanpa bulan/tahun: tanggal terdekat yang sudah lewat. Null bila tanggalnya tidak ada. */
    private function dayOfMonth(CarbonImmutable $today, int $day, string $month, string $year): ?CarbonImmutable
    {
        $base = $today->startOfMonth();
        $m = $month !== '' ? self::MONTHS[$month] : $base->month;
        $y = $year !== '' ? (int) $year : $base->year;

        if ($year === '' && ($m > $today->month || ($m === $today->month && $day > $today->day))) {
            if ($month === '') {
                $previous = $base->subMonth();
                [$y, $m] = [$previous->year, $previous->month];
            } else {
                $y--;
            }
        }

        return checkdate($m, $day, $y) ? CarbonImmutable::create($y, $m, $day)->startOfDay() : null;
    }

    /* ---------------------------------------------------------------
       Akun
       --------------------------------------------------------------- */

    /** @return list<array{0: string, 1: int}> pola kata + id akun; nama lebih panjang didahulukan */
    private function accountPatterns(Collection $accounts): array
    {
        $patterns = $accounts
            ->sortByDesc(fn ($a) => mb_strlen($a->name))
            ->map(fn ($a) => [preg_quote(Str::lower(trim($a->name)), '/'), $a->id])
            ->values()->all();

        $cash = $accounts->firstWhere('type', 'cash');
        if ($cash) {
            $patterns[] = ['tunai|cash|dompet|kas', $cash->id];
        }
        // "rekening" hanya bermakna bila akun banknya satu.
        $banks = $accounts->where('type', 'bank')->values();
        if ($banks->count() === 1) {
            $patterns[] = ['rekening|bank', $banks[0]->id];
        }

        return $patterns;
    }

    /** @return list<array{id: int, start: int}> akun yang disebut, urut kemunculan */
    private function mentions(string $s, Collection $accounts): array
    {
        $found = [];
        foreach ($this->accountPatterns($accounts) as [$pattern, $id]) {
            if (! isset($found[$id]) && preg_match('/\b(?:'.$pattern.')\b/u', $s, $m, PREG_OFFSET_CAPTURE)) {
                $found[$id] = ['id' => $id, 'start' => $m[0][1]];
                // Kosongkan supaya alias "kas" tidak terbaca di akun bernama "Kas Kecil" dua kali.
                $s = substr_replace($s, str_repeat(' ', strlen($m[0][0])), $m[0][1], strlen($m[0][0]));
            }
        }
        usort($found, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $found;
    }

    /** @return array{0: ?int, 1: ?int} akun sumber + tujuan; yang tidak jelas dibiarkan null agar ditinjau */
    private function transferAccounts(string $s, Collection $accounts): array
    {
        $from = null;
        $to = null;
        $others = [];

        foreach ($this->mentions($s, $accounts) as $mention) {
            $before = substr($s, 0, $mention['start']);
            if (preg_match('/\bdari\s+$/u', $before)) {
                $from = $mention['id'];
            } elseif (preg_match('/\bke\s+$/u', $before)) {
                $to = $mention['id'];
            } else {
                $others[] = $mention['id'];
            }
        }

        if (preg_match(self::WITHDRAW, $s)) {
            $cashId = $accounts->firstWhere('type', 'cash')?->id;
            $to ??= $cashId;
            $others = array_values(array_filter($others, fn ($id) => $id !== $to));
            $banks = $accounts->where('type', 'bank')->values();
            $from ??= array_shift($others) ?? ($banks->count() === 1 ? $banks[0]->id : null);
        } elseif (preg_match(self::TOP_UP, $s)) {
            $to ??= array_shift($others);
            $from ??= array_shift($others);
        } else {
            $from ??= array_shift($others);
            $to ??= array_shift($others);
        }

        return [$from, $to];
    }

    /* ---------------------------------------------------------------
       Kategori: nama yang disebut, lalu Naive Bayes dari riwayat
       --------------------------------------------------------------- */

    /** @return list<string> */
    private function tokens(string $s): array
    {
        preg_match_all('/\p{L}{2,}/u', Str::lower($s), $m);

        return array_values(array_diff($m[0], self::STOPWORDS));
    }

    /** @param list<array{text: string, category_id: int}> $training */
    private function classifier(array $training): array
    {
        $words = [];
        $docs = [];
        $vocabulary = [];

        foreach ($training as $row) {
            $id = $row['category_id'];
            $docs[$id] = ($docs[$id] ?? 0) + 1;
            foreach ($this->tokens($row['text']) as $token) {
                $words[$id][$token] = ($words[$id][$token] ?? 0) + 1;
                $vocabulary[$token] = true;
            }
        }

        return ['words' => $words, 'docs' => $docs, 'vocabulary' => $vocabulary];
    }

    private function category(string $words, string $type, Collection $categories, array $classifier): ?int
    {
        $tokens = $this->tokens($words);
        // Kategori [darurat] hanya bila pengguna menyebutnya (sama dengan aturan prompt AI).
        $urgent = (bool) preg_match('/\b(darurat|mendesak|tak terduga|tidak terduga)\b/u', $words);
        $candidates = $categories->where('type', $type)->filter(fn ($c) => $urgent || ! ($c->urgent ?? false))->values();

        return $this->named($candidates, $tokens)?->id ?? $this->classify($tokens, $candidates, $classifier)?->id;
    }

    /** Kategori yang semua kata namanya disebut; "gajian" cocok dengan Gaji. */
    private function named(Collection $candidates, array $tokens): ?object
    {
        return $candidates->first(function ($c) use ($tokens) {
            $name = $this->tokens($c->name);

            return $name !== [] && collect($name)->every(fn ($word) => collect($tokens)->contains(
                fn ($token) => $token === $word || (min(strlen($word), strlen($token)) >= 4 && (str_starts_with($token, $word) || str_starts_with($word, $token)))
            ));
        });
    }

    /** Kategori paling mungkin, atau null bila tak ada kata yang dikenal atau kurang yakin. */
    private function classify(array $tokens, Collection $candidates, array $classifier): ?object
    {
        $known = array_values(array_filter($tokens, fn ($t) => isset($classifier['vocabulary'][$t])));
        if ($known === [] || $candidates->isEmpty()) {
            return null;
        }

        $vocabulary = count($classifier['vocabulary']) * self::SMOOTHING;
        $documents = array_sum($classifier['docs']);
        $scores = [];
        $seen = [];

        foreach ($candidates as $category) {
            $words = $classifier['words'][$category->id] ?? [];
            if (! isset($classifier['docs'][$category->id])) {
                continue;
            }
            $total = array_sum($words);
            $score = log($classifier['docs'][$category->id] / $documents);
            foreach ($known as $token) {
                $score += log((($words[$token] ?? 0) + self::SMOOTHING) / ($total + $vocabulary));
                $seen[$category->id] = ($seen[$category->id] ?? false) || isset($words[$token]);
            }
            $scores[$category->id] = $score;
        }
        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $best = array_key_first($scores);
        $top = $scores[$best];
        $confidence = 1 / array_sum(array_map(fn ($score) => exp($score - $top), $scores));

        // Tanpa pesaing keyakinannya selalu 1, jadi kata itu juga harus pernah muncul di kategori ini.
        return $confidence >= self::MIN_CONFIDENCE && ($seen[$best] ?? false)
            ? $candidates->firstWhere('id', $best)
            : null;
    }

    /* ---------------------------------------------------------------
       Pertanyaan: dihitung dari data yang sama dengan konteks AI
       --------------------------------------------------------------- */

    private function answer(string $s, Collection $accounts, Collection $categories, ?object $totals): array
    {
        $cannot = 'Mode hemat: LAX hanya bisa menjawab saldo, serta pengeluaran, pemasukan, dan anggaran periode berjalan.';
        if (preg_match(self::PERIOD_OTHER, $s)) {
            return $this->result(unclear: $cannot);
        }

        $tokens = $this->tokens($s);
        $category = $this->named($categories->where('type', 'expense'), $tokens);
        $rupiah = fn ($value) => VoiceAssistant::rupiah((int) $value);

        if (preg_match('/\b(anggaran|budget)(?:ku|nya)?\b/u', $s)) {
            if (! $category) {
                return $this->result(unclear: 'Sebutkan kategorinya, misalnya "sisa anggaran makan".');
            }

            return $this->result(reply: $category->budget
                ? "Sisa anggaran {$category->name} ".$rupiah($category->budget - (int) ($category->spent ?? 0)).'.'
                : "{$category->name} belum punya anggaran.");
        }
        if (preg_match('/\b(pengeluaran|keluar|belanja|habis|terpakai)(?:ku|nya)?\b/u', $s)) {
            return $this->result(reply: $category
                ? "Pengeluaran {$category->name} periode ini ".$rupiah($category->spent ?? 0).'.'
                : 'Pengeluaran periode ini '.$rupiah($totals->expense ?? 0).'.');
        }
        if (preg_match('/\b(pemasukan|pendapatan|masuk)(?:ku|nya)?\b/u', $s)) {
            return $this->result(reply: 'Pemasukan periode ini '.$rupiah($totals->income ?? 0).'.');
        }
        if (preg_match('/saldo|\buang(?:ku)?\b|\bduit(?:ku)?\b/u', $s)) {
            $mention = $this->mentions($s, $accounts)[0]['id'] ?? null;
            $account = $mention ? $accounts->firstWhere('id', $mention) : null;

            return $this->result(reply: $account
                ? "Saldo {$account->name} ".$rupiah($account->balance).'.'
                : 'Total saldo akun aktif '.$rupiah($accounts->sum('balance')).'.');
        }

        return $this->result(unclear: $cannot);
    }
}
