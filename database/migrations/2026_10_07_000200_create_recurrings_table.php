<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jadwal transaksi berulang (gaji, kos, langganan). Tidak dicatat otomatis:
        // saat jatuh tempo, pengguna mengonfirmasi lewat kartu di beranda.
        Schema::create('recurrings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // income | expense | transfer
            $table->unsignedBigInteger('amount');
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 160)->nullable();
            $table->string('frequency', 16); // weekly | monthly | yearly
            $table->date('start_on'); // jangkar tanggal (mis. tiap tgl 31 → akhir bulan)
            $table->date('next_due');
            $table->timestamps();

            $table->index(['user_id', 'next_due']);
        });

        // Supabase: tutup akses Data API seperti tabel lain (lihat enable_row_level_security).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "recurrings" ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrings');
    }
};
