<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan pemakaian AI (asisten LAX & suara) per panggilan API, untuk halaman "Pemakaian AI".
 * Google tidak menyediakan API sisa kuota, jadi Sisih menghitung pemakaiannya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 16); // gemini | claude
            $table->string('model', 80);
            $table->string('feature', 16); // assistant | voice
            $table->string('status', 16); // ok | rate_limited | error
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('thought_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
