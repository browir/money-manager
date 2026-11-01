<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contoh latih pengurai lokal LAX: ucapan, usulan AI, dan hasil akhir yang disimpan pengguna.
 * Rencana lengkap: docs/rencana-pengurai-lokal.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('model', 80)->nullable();
            $table->unsignedBigInteger('default_account_id')->nullable(); // akun bawaan klien saat itu
            $table->json('proposal');
            $table->json('final')->nullable(); // null = usulan tidak pernah dijalankan
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Tutup akses Data API Supabase (lihat enable_row_level_security): isinya ucapan pribadi.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "assistant_examples" ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_examples');
    }
};
