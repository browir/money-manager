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
        $data = $request->validate(
            [...$this->transactionRules($request->user()->id, $request->input('type')), ...$extraRules],
            $this->transactionMessages(),
        );

        return $this->normalizeTransaction($data);
    }

    /**
     * @param  string  $prefix  awalan nama kolom untuk isian bertingkat, mis. "items.0."
     * @return array<string, mixed>
     */
    protected function transactionRules(int $userId, mixed $type, string $prefix = ''): array
    {
        $ownedAccount = Rule::exists('accounts', 'id')->where('user_id', $userId);

        return [
            "{$prefix}type" => ['required', Rule::in(Transaction::TYPES)],
            "{$prefix}amount" => ['required', 'integer', 'min:1', 'max:999999999999'],
            "{$prefix}account_id" => ['required', 'integer', $ownedAccount],
            "{$prefix}to_account_id" => ['nullable', "required_if:{$prefix}type,transfer", 'integer', "different:{$prefix}account_id", $ownedAccount],
            "{$prefix}category_id" => ['nullable', 'integer', Rule::exists('categories', 'id')
                ->where('user_id', $userId)
                ->where('type', $type === 'income' ? 'income' : 'expense')],
            "{$prefix}note" => ['nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<string, string> */
    protected function transactionMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}amount.min" => 'Nominal belum diisi.',
            "{$prefix}amount.required" => 'Nominal belum diisi.',
            "{$prefix}to_account_id.required_if" => 'Pilih akun tujuan.',
            "{$prefix}to_account_id.different" => 'Akun tujuan harus berbeda.',
        ];
    }

    /** Transfer tidak berkategori; selain transfer tidak punya akun tujuan. */
    protected function normalizeTransaction(array $data): array
    {
        if ($data['type'] === 'transfer') {
            $data['category_id'] = null;
        } else {
            $data['to_account_id'] = null;
        }

        return $data;
    }
}
