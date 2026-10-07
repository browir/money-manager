<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recurring extends Model
{
    public const FREQUENCIES = ['weekly', 'monthly', 'yearly'];

    protected $fillable = [
        'type', 'amount', 'account_id', 'to_account_id', 'category_id', 'note', 'frequency', 'start_on', 'next_due',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'start_on' => 'immutable_date',
            'next_due' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Jatuh tempo setelah $date. Bulanan/tahunan memakai tanggal dari start_on,
     * dipotong ke akhir bulan bila perlu: tgl 31 → 30 Nov → 31 Des (tidak bergeser).
     */
    public function following(CarbonImmutable $date): CarbonImmutable
    {
        if ($this->frequency === 'weekly') {
            return $date->addWeek();
        }

        $base = $date->startOfMonth()->{$this->frequency === 'yearly' ? 'addYear' : 'addMonth'}();

        return $base->setDay(min($this->start_on->day, $base->daysInMonth));
    }

    /** Majukan ke periode berikutnya (setelah dicatat atau dilewati). */
    public function advance(): void
    {
        $this->update(['next_due' => $this->following($this->next_due)]);
    }

    /** Isian transaksi untuk periode yang sedang jatuh tempo. */
    public function transactionAttributes(): array
    {
        return [
            'type' => $this->type,
            'amount' => $this->amount,
            'account_id' => $this->account_id,
            'to_account_id' => $this->to_account_id,
            'category_id' => $this->category_id,
            'note' => $this->note,
            'occurred_on' => $this->next_due->toDateString(),
        ];
    }

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
            'frequency' => $this->frequency,
            'start_on' => $this->start_on->toDateString(),
            'next_due' => $this->next_due->toDateString(),
        ];
    }
}
