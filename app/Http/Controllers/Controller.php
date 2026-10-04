<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    /** Data milik pengguna lain diperlakukan seolah tidak ada. */
    protected function ensureOwned(Model $model): void
    {
        abort_unless($model->user_id === auth()->id(), 404);
    }

    /** Bulan dari query string `?month=2026-10`, default bulan berjalan. */
    protected function month(?string $value): CarbonImmutable
    {
        if ($value && preg_match('/^\d{4}-\d{2}$/', $value)) {
            return CarbonImmutable::createFromFormat('!Y-m', $value)->startOfMonth();
        }

        return CarbonImmutable::now()->startOfMonth();
    }
}
