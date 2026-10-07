<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // UUID dari perangkat untuk transaksi yang dicatat saat offline: kalau kiriman
            // diulang (respons hilang di jalan), transaksi tidak tercatat dua kali.
            $table->uuid('client_id')->nullable()->after('user_id');
            $table->unique(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_id']);
            $table->dropColumn('client_id');
        });
    }
};
