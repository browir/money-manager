<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Kategori darurat: pengeluarannya tidak mengurangi anggaran bulanan.
            $table->boolean('urgent')->default(false)->after('budget');
        });

        // Setiap pengguna yang sudah punya kategori mendapat satu kategori "Darurat".
        // Bila sudah ada kategori pengeluaran bernama "Darurat", cukup ditandai.
        $now = now();
        foreach (DB::table('categories')->distinct()->pluck('user_id') as $userId) {
            $existing = DB::table('categories')
                ->where('user_id', $userId)->where('type', 'expense')
                ->whereRaw('LOWER(name) = ?', ['darurat'])
                ->value('id');

            if ($existing) {
                DB::table('categories')->where('id', $existing)->update(['urgent' => true, 'budget' => null]);

                continue;
            }

            $sort = (int) DB::table('categories')->where('user_id', $userId)->where('type', 'expense')->max('sort') + 1;
            DB::table('categories')->insert([
                'user_id' => $userId, 'name' => 'Darurat', 'type' => 'expense', 'icon' => 'siren', 'color' => 'rose',
                'urgent' => true, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('urgent');
        });
    }
};
