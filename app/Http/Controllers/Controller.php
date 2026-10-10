<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    /** Data milik pengguna lain diperlakukan seolah tidak ada. */
    protected function ensureOwned(Model $model): void
    {
        abort_unless($model->user_id === auth()->id(), 404);
    }
}
