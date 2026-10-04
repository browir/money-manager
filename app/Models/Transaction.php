<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    public const TYPES = ['income', 'expense', 'transfer'];

    protected $fillable = ['type', 'amount', 'account_id', 'to_account_id', 'category_id', 'note', 'occurred_on'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_on' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Bentuk ringkas untuk dikirim ke halaman. */
    public function toListItem(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => $this->amount,
            'account_id' => $this->account_id,
            'to_account_id' => $this->to_account_id,
            'category_id' => $this->category_id,
            'note' => $this->note,
            'occurred_on' => $this->occurred_on->format('Y-m-d'),
        ];
    }
}
