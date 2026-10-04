<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
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

    Route::get('/pengaturan', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/pengaturan/profil', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('/pengaturan/sandi', [SettingsController::class, 'updatePassword'])->name('settings.password');
});

// SEMENTARA: diagnosis koneksi database di Vercel. Hapus setelah beres.
Route::get('/_diag/{token}', function (string $token) {
    abort_unless(hash_equals(hash_hmac('sha256', 'diag', (string) config('app.key')), $token), 404);

    $out = [
        'php' => PHP_VERSION,
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'pdo_drivers' => PDO::getAvailableDrivers(),
        'db_default' => config('database.default'),
        'db_url_set' => (bool) env('DB_URL'),
        'db_url_scheme_host_port' => ($u = parse_url((string) env('DB_URL'))) ? (($u['scheme'] ?? '?').'://'.($u['host'] ?? '?').':'.($u['port'] ?? '?').' user='.($u['user'] ?? '?')) : null,
        'sslmode' => config('database.connections.pgsql.sslmode'),
    ];

    try {
        \Illuminate\Support\Facades\DB::connection('pgsql')->getPdo();
        $out['connect'] = 'ok';
        $out['tables'] = collect(\Illuminate\Support\Facades\DB::connection('pgsql')->select("select tablename from pg_tables where schemaname = 'public' order by 1"))->pluck('tablename');
        $out['users'] = \Illuminate\Support\Facades\DB::connection('pgsql')->table('users')->count();
    } catch (\Throwable $e) {
        $pass = parse_url((string) env('DB_URL'), PHP_URL_PASS);
        $msg = $e->getMessage();
        if ($pass) {
            $msg = str_replace([$pass, rawurldecode($pass)], '***', $msg);
        }
        $out['error'] = get_class($e).': '.mb_substr($msg, 0, 600);
    }

    return response()->json($out);
});
