<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const TYPES = ['cash', 'bank', 'ewallet', 'other'];

    protected $fillable = ['name', 'type', 'color', 'initial_balance', 'sort', 'archived_at'];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'integer',
            'balance' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Saldo = saldo awal + uang masuk - uang keluar (transfer dihitung di kedua sisi)
     * + penyesuaian saldo (amount bertanda).
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->select('accounts.*')->selectRaw(<<<'SQL'
            accounts.initial_balance + COALESCE((
                SELECT SUM(CASE
                    WHEN t.type = 'income' THEN t.amount
                    WHEN t.type = 'adjustment' THEN t.amount
                    WHEN t.type = 'transfer' AND t.to_account_id = accounts.id THEN t.amount
                    ELSE -t.amount
                END)
                FROM transactions t
                WHERE t.deleted_at IS NULL
                  AND (t.account_id = accounts.id OR t.to_account_id = accounts.id)
            ), 0) AS balance
        SQL);
    }
}
