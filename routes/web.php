<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RecurringController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [LoginController::class, 'create'])->name('login');
    Route::post('/masuk', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/transaksi', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/transaksi', [TransactionController::class, 'store'])->name('transactions.store');
    Route::put('/transaksi/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('/transaksi/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
    Route::post('/transaksi/{id}/kembalikan', [TransactionController::class, 'restore'])->name('transactions.restore');

    Route::get('/akun', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/akun', [AccountController::class, 'store'])->name('accounts.store');
    Route::put('/akun/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('/akun/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    Route::get('/kategori', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/kategori', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('/kategori/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/kategori/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('/berulang', [RecurringController::class, 'index'])->name('recurring.index');
    Route::post('/berulang', [RecurringController::class, 'store'])->name('recurring.store');
    Route::put('/berulang/{recurring}', [RecurringController::class, 'update'])->name('recurring.update');
    Route::delete('/berulang/{recurring}', [RecurringController::class, 'destroy'])->name('recurring.destroy');
    Route::post('/berulang/{recurring}/catat', [RecurringController::class, 'record'])->name('recurring.record');
    Route::post('/berulang/{recurring}/lewati', [RecurringController::class, 'skip'])->name('recurring.skip');

    Route::get('/pengaturan', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/pengaturan/profil', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('/pengaturan/sandi', [SettingsController::class, 'updatePassword'])->name('settings.password');
});

/*
 * Migrasi + akun pemilik di produksi, dipicu manual setelah deploy:
 *   https://<domain>/_ops/migrate/<token>
 * Token = HMAC-SHA256("ops-migrate", APP_KEY), jadi hanya pemegang APP_KEY yang bisa memanggilnya.
 * (Build Vercel tidak menerima env dashboard, jadi migrasi saat build tidak bisa diandalkan.)
 */
Route::get('/_ops/migrate/{token}', function (string $token) {
    abort_unless(hash_equals(hash_hmac('sha256', 'ops-migrate', (string) config('app.key')), $token), 404);

    $output = '';

    try {
        Artisan::call('migrate', ['--force' => true]);
        $output .= Artisan::output();

        Artisan::call('db:seed', ['--force' => true]);
        $output .= Artisan::output();
    } catch (Throwable $e) {
        return response($output."
GAGAL: ".get_class($e).': '.$e->getMessage(), 500, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    return response(trim($output) ?: 'Tidak ada perubahan.', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});
