<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase otomatis membuka tabel schema `public` lewat Data API (PostgREST).
 * RLS tanpa policy menutup akses itu sepenuhnya; Laravel tetap bisa membaca/menulis
 * karena terhubung sebagai pemilik tabel (role postgres), yang tidak terkena RLS.
 */
return new class extends Migration
{
    private array $tables = [
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'accounts', 'categories', 'transactions',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE IF EXISTS \"{$table}\" ENABLE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE IF EXISTS \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }
};
