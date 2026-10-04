<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Dijalankan otomatis saat build di Vercel (composer.json -> scripts.vercel).
 * Memakai koneksi pgsql secara eksplisit karena env di vercel.json hanya berlaku saat runtime,
 * sedangkan DB_URL / OWNER_* dari dashboard Vercel tersedia saat build.
 */
Artisan::command('app:deploy', function () {
    if (! env('DB_URL')) {
        $this->warn('DB_URL belum diisi: migrasi dilewati.');

        return 0;
    }

    config(['database.default' => 'pgsql']);

    $this->call('migrate', ['--force' => true]);

    // Seeder aman dijalankan berulang: akun & kategori hanya dibuat kalau belum ada.
    $this->call('db:seed', ['--force' => true]);

    return 0;
})->purpose('Migrasi + akun pemilik di database produksi (dipakai saat deploy)');
