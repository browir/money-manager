<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesTransactionFields;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransactionController extends Controller
{
    use ValidatesTransactionFields;

    /** Batas baris untuk rentang panjang (semua waktu / pilih tanggal); total tetap dihitung penuh. */
    private const LIST_LIMIT = 500;

    public function index(Request $request)
    {
        $user = $request->user();
        $period = $user->period($request->query('month'));
        $filters = $request->only(['type', 'account', 'category', 'q']);

        // Rentang: periode (bawaan), semua waktu (?all=1), atau tanggal bebas (?from=&to=).
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? $request->query($key) : null;
        [$from, $to] = [$date('from'), $date('to')];
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $mode = $request->boolean('all') ? 'all' : ($from || $to ? 'custom' : 'period');

        $query = $user->transactions()
            ->when($mode === 'period', fn ($q) => $q->whereBetween('occurred_on', $period->range()))
            ->when($mode === 'custom' && $from, fn ($q) => $q->where('occurred_on', '>=', $from))
            ->when($mode === 'custom' && $to, fn ($q) => $q->where('occurred_on', '<=', $to))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['account'] ?? null, fn ($q, $id) => $q->where(
                fn ($q) => $q->where('account_id', $id)->orWhere('to_account_id', $id)
            ))
            ->when($filters['category'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            // LOWER() agar tidak peka huruf besar/kecil: LIKE di Postgres (produksi) peka huruf.
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->whereRaw('LOWER(note) LIKE ?', [$like])
                    ->orWhereHas('category', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]));
            }));

        $totals = (clone $query)
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense")
            ->toBase()
            ->first();

        $transactions = $query
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->when($mode !== 'period', fn ($q) => $q->limit(self::LIST_LIMIT))
            ->get()
            ->map->toListItem();

        return Inertia::render('transactions/Index', [
            'month' => $period->key(),
            'filters' => (object) array_filter($filters),
            'range' => ['mode' => $mode, 'from' => $from, 'to' => $to],
            'totals' => [
                'count' => (int) $totals->count,
                'income' => (int) $totals->income,
                'expense' => (int) $totals->expense,
            ],
            'transactions' => $transactions,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $extra = $request->validate([
            'client_id' => ['nullable', 'uuid'], // dari antrean offline
            'recurring_id' => ['nullable', 'integer'], // dicatat dari jadwal berulang
        ]);
        $clientId = $extra['client_id'] ?? null;

        // Kiriman ulang dari antrean offline (respons sebelumnya hilang): jangan dobel.
        $existing = $clientId ? $user->transactions()->withTrashed()->where('client_id', $clientId)->first() : null;
        if ($existing) {
            return $this->stored($request, $existing);
        }

        $transaction = $user->transactions()->create([...$this->validated($request), 'client_id' => $clientId]);

        if ($recurringId = $extra['recurring_id'] ?? null) {
            $user->recurrings()->find($recurringId)?->advance();
        }

        return $this->stored($request, $transaction);
    }

    /** Antrean offline mengirim lewat fetch biasa (JSON); form Inertia kembali ke halaman asal. */
    private function stored(Request $request, Transaction $transaction)
    {
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => $transaction->id], 201);
        }

        // Konfirmasi visual (animasi) ditangani di klien; cukup kirim id untuk disorot.
        Inertia::flash('saved', $transaction->id);

        return back();
    }

    public function update(Request $request, Transaction $transaction)
    {
        $this->ensureOwned($transaction);

        // Penyesuaian saldo tidak diubah lewat form transaksi: hapus lalu sesuaikan ulang.
        if ($transaction->type === Transaction::ADJUSTMENT) {
            Inertia::flash('toast', ['message' => 'Penyesuaian saldo tidak bisa diubah, hapus lalu sesuaikan ulang']);

            return back();
        }

        $transaction->update($this->validated($request));

        Inertia::flash('saved', $transaction->id);

        return back();
    }

    public function destroy(Transaction $transaction)
    {
        $this->ensureOwned($transaction);
        $transaction->delete();

        Inertia::flash('toast', [
            'message' => $this->label($transaction).' dihapus',
            'undo' => route('transactions.restore', $transaction->id),
        ]);

        return back();
    }

    public function restore(int $id)
    {
        $transaction = Transaction::onlyTrashed()->findOrFail($id);
        $this->ensureOwned($transaction);
        $transaction->restore();

        Inertia::flash('toast', ['message' => 'Dikembalikan']);

        return back();
    }

    private function validated(Request $request): array
    {
        return $this->validateTransactionFields($request, [
            'occurred_on' => ['required', 'date_format:Y-m-d'],
        ]);
    }

    private function label(Transaction $transaction): string
    {
        return match ($transaction->type) {
            'income' => 'Pemasukan',
            'expense' => 'Pengeluaran',
            'transfer' => 'Transfer',
            Transaction::ADJUSTMENT => 'Penyesuaian saldo',
        };
    }
}
