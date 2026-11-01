<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesTransactionFields;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\LaxVoice;
use App\Support\VoiceAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/** Asisten suara: urai ucapan jadi usulan, lalu jalankan usulan yang sudah ditinjau pengguna. */
class AssistantController extends Controller
{
    use ValidatesTransactionFields;

    /** Langkah 1 (fetch JSON): ucapan -> jawaban + usulan. Belum ada yang disimpan. */
    public function parse(Request $request, VoiceAssistant $assistant)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
            'default_account_id' => ['nullable', 'integer'],
            'call_name' => ['nullable', 'string', 'max:30'],
        ]);

        if (! $assistant->enabled()) {
            return response()->json(['message' => 'Asisten belum aktif: isi GEMINI_API_KEY (atau ANTHROPIC_API_KEY) di server.'], 503);
        }

        try {
            return response()->json($assistant->handle($request->user(), $data['text'], $data['default_account_id'] ?? null, $data['call_name'] ?? null));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /** Suara LAX dari Gemini: teks pendek (tanpa nominal) -> audio WAV. Klien jatuh ke suara perangkat bila gagal. */
    public function speak(Request $request, LaxVoice $voice)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:400'],
            'voice' => ['nullable', Rule::in(array_keys(LaxVoice::VOICES))],
        ]);

        if (! $voice->enabled()) {
            return response()->json(['message' => 'Suara Gemini butuh GEMINI_API_KEY.'], 503);
        }

        try {
            $wav = $voice->synthesize($data['text'], $data['voice'] ?? LaxVoice::DEFAULT_VOICE);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response($wav, 200, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * Langkah 2: jalankan usulan yang dikonfirmasi, semua atau tidak sama sekali.
     * Urutan: buat/ganti nama/aktifkan akun -> transaksi -> sesuaikan saldo -> arsipkan,
     * supaya "beli bakso 25rb, saldo dompet sekarang 300rb" menghasilkan saldo akhir 300rb.
     */
    public function apply(Request $request)
    {
        $user = $request->user();
        $max = 'max:'.VoiceAssistant::MAX_ITEMS;
        $request->validate([
            'items' => ['array', $max],
            'account_actions' => ['array', $max],
        ]);
        if (! $request->input('items') && ! $request->input('account_actions')) {
            return back()->withErrors(['items' => 'Tidak ada yang perlu dijalankan.']);
        }

        $rules = [];
        foreach (array_keys($request->input('items', [])) as $i) {
            $rules = [
                ...$rules,
                ...$this->transactionRules($user->id, $request->input("items.{$i}.type"), "items.{$i}."),
                "items.{$i}.occurred_on" => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            ];
        }

        $ownedAccount = Rule::exists('accounts', 'id')->where('user_id', $user->id);
        $amount = ['integer', 'between:-999999999999,999999999999'];
        foreach (array_keys($request->input('account_actions', [])) as $i) {
            $p = "account_actions.{$i}.";
            $action = $request->input("{$p}action");
            $rules = [
                ...$rules,
                "{$p}action" => ['required', Rule::in(VoiceAssistant::ACCOUNT_ACTIONS)],
                "{$p}account_id" => $action === 'create' ? ['nullable'] : ['required', 'integer', $ownedAccount],
                "{$p}name" => in_array($action, ['create', 'rename'], true) ? ['required', 'string', 'max:60'] : ['nullable'],
                "{$p}type" => $action === 'create' ? ['required', Rule::in(Account::TYPES)] : ['nullable'],
                "{$p}amount" => $action === 'adjust_balance' ? ['required', ...$amount] : ['nullable', ...$amount],
                "{$p}note" => ['nullable', 'string', 'max:160'],
            ];
        }

        $data = $request->validate($rules, [
            ...$this->transactionMessages('items.*.'),
            'items.*.occurred_on.before_or_equal' => 'Tanggal tidak boleh melewati hari ini.',
            'account_actions.*.name.required' => 'Nama akun wajib diisi.',
            'account_actions.*.amount.required' => 'Isi saldo sebenarnya.',
        ]);

        $actions = collect($data['account_actions'] ?? []);
        $phase = fn (array $names) => $actions->filter(fn ($a) => in_array($a['action'], $names, true));

        $lastTransaction = DB::transaction(function () use ($user, $data, $phase) {
            $account = fn ($a) => $user->accounts()->findOrFail($a['account_id']);
            $last = null;

            foreach ($phase(['create', 'rename', 'unarchive']) as $a) {
                match ($a['action']) {
                    'create' => $user->accounts()->create([
                        'name' => $a['name'],
                        'type' => $a['type'],
                        'color' => VoiceAssistant::ACCOUNT_COLORS[$a['type']],
                        'initial_balance' => $a['amount'] ?? 0,
                        'sort' => $user->accounts()->max('sort') + 1,
                    ]),
                    'rename' => $account($a)->update(['name' => $a['name']]),
                    'unarchive' => $account($a)->update(['archived_at' => null]),
                };
            }

            foreach ($data['items'] ?? [] as $item) {
                $last = $user->transactions()->create($this->normalizeTransaction($item));
            }

            // Saldo dihitung saat dijalankan (setelah transaksi di atas), bukan saat diurai.
            foreach ($phase(['adjust_balance']) as $a) {
                $diff = $a['amount'] - (int) Account::withBalance()->whereKey($a['account_id'])->value('balance');
                if ($diff !== 0) {
                    $last = $user->transactions()->create([
                        'type' => Transaction::ADJUSTMENT,
                        'amount' => $diff,
                        'account_id' => $a['account_id'],
                        'note' => $a['note'] ?? null,
                        'occurred_on' => now()->toDateString(),
                    ]);
                }
            }

            foreach ($phase(['archive']) as $a) {
                $account($a)->update(['archived_at' => now()]);
            }

            return $last;
        });

        $count = count($data['items'] ?? []) + $actions->count();
        if ($lastTransaction) {
            Inertia::flash('saved', $lastTransaction->id);
        }
        // Satu transaksi saja sudah dirayakan animasi di klien; selain itu beri ringkasan.
        if ($actions->isNotEmpty() || $count > 1) {
            Inertia::flash('toast', ['message' => $count === 1 ? 'Perubahan disimpan' : "{$count} perubahan disimpan"]);
        }

        return back();
    }
}
