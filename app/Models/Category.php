<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    public const TYPES = ['income', 'expense'];

    protected $fillable = ['name', 'type', 'icon', 'color', 'budget', 'urgent', 'sort'];

    protected function casts(): array
    {
        return [
            'budget' => 'integer',
            'urgent' => 'boolean',
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
}
