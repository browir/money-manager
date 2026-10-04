<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $month = $this->month($request->query('month'));
        $filters = $request->only(['type', 'account', 'category', 'q']);

        $transactions = $user->transactions()
            ->whereBetween('occurred_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['account'] ?? null, fn ($q, $id) => $q->where(
                fn ($q) => $q->where('account_id', $id)->orWhere('to_account_id', $id)
            ))
            ->when($filters['category'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('note', 'like', '%'.$term.'%'))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get()
            ->map->toListItem();

        return Inertia::render('transactions/Index', [
            'month' => $month->format('Y-m'),
            'filters' => (object) array_filter($filters),
            'transactions' => $transactions,
        ]);
    }

    public function store(Request $request)
    {
        $transaction = $request->user()->transactions()->create($this->validated($request));

        Inertia::flash('toast', [
            'message' => $this->label($transaction).' tersimpan',
        ]);

        return back();
    }

    public function update(Request $request, Transaction $transaction)
    {
        $this->ensureOwned($transaction);
        $transaction->update($this->validated($request));

        Inertia::flash('toast', ['message' => 'Perubahan tersimpan']);

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
        $userId = $request->user()->id;
        $ownedAccount = Rule::exists('accounts', 'id')->where('user_id', $userId);
        $type = $request->input('type');

        $data = $request->validate([
            'type' => ['required', Rule::in(Transaction::TYPES)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'account_id' => ['required', 'integer', $ownedAccount],
            'to_account_id' => ['nullable', 'required_if:type,transfer', 'integer', 'different:account_id', $ownedAccount],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')
                ->where('user_id', $userId)
                ->where('type', $type === 'income' ? 'income' : 'expense')],
            'note' => ['nullable', 'string', 'max:160'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
        ], [
            'amount.min' => 'Nominal belum diisi.',
            'amount.required' => 'Nominal belum diisi.',
            'to_account_id.required_if' => 'Pilih akun tujuan.',
            'to_account_id.different' => 'Akun tujuan harus berbeda.',
        ]);

        if ($type === 'transfer') {
            $data['category_id'] = null;
        } else {
            $data['to_account_id'] = null;
        }

        return $data;
    }

    private function label(Transaction $transaction): string
    {
        return match ($transaction->type) {
            'income' => 'Pemasukan',
            'expense' => 'Pengeluaran',
            'transfer' => 'Transfer',
        };
    }
}
