<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Aturan isian transaksi, dipakai transaksi biasa maupun jadwal berulang. */
trait ValidatesTransactionFields
{
    /**
     * @param  array<string, mixed>  $extraRules  aturan tambahan (mis. tanggal / frekuensi)
     */
    protected function validateTransactionFields(Request $request, array $extraRules = []): array
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
            ...$extraRules,
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
}
