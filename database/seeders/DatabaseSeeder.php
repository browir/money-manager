<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Membuat akun pemilik beserta akun & kategori bawaan.
     * Kredensial diambil dari OWNER_NAME / OWNER_EMAIL / OWNER_PASSWORD di .env.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => env('OWNER_EMAIL', 'saya@saku.test')],
            [
                'name' => env('OWNER_NAME', 'Pemilik'),
                'password' => env('OWNER_PASSWORD', 'rahasia123'),
            ],
        );

        if ($user->accounts()->doesntExist()) {
            foreach ([
                ['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss'],
                ['name' => 'Bank', 'type' => 'bank', 'color' => 'ink'],
                ['name' => 'E-wallet', 'type' => 'ewallet', 'color' => 'iris'],
            ] as $i => $account) {
                $user->accounts()->create($account + ['sort' => $i, 'initial_balance' => 0]);
            }
        }

        if ($user->categories()->doesntExist()) {
            $expense = [
                ['Makan & Minum', 'utensils', 'clay'],
                ['Belanja', 'shopping-bag', 'rose'],
                ['Transportasi', 'car', 'teal'],
                ['Tagihan', 'receipt', 'ink'],
                ['Rumah', 'house', 'sand'],
                ['Kesehatan', 'heart-pulse', 'rose'],
                ['Hiburan', 'clapperboard', 'plum'],
                ['Pendidikan', 'graduation-cap', 'iris'],
                ['Pulsa & Internet', 'wifi', 'iris'],
                ['Donasi', 'hand-heart', 'moss'],
                ['Lainnya', 'shapes', 'slate'],
            ];
            $income = [
                ['Gaji', 'briefcase', 'moss'],
                ['Bonus', 'gift', 'teal'],
                ['Usaha', 'store', 'sand'],
                ['Investasi', 'trending-up', 'ink'],
                ['Lainnya', 'shapes', 'slate'],
            ];

            foreach (['expense' => $expense, 'income' => $income] as $type => $items) {
                foreach ($items as $i => [$name, $icon, $color]) {
                    $user->categories()->create(compact('name', 'icon', 'color', 'type') + ['sort' => $i]);
                }
            }
        }
    }
}
