<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Data contoh 3 bulan terakhir untuk mencoba tampilan.
 * Jalankan: php artisan db:seed --class=DemoSeeder
 * Hapus lagi: php artisan migrate:fresh --seed
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrFail();
        $accounts = $user->accounts()->pluck('id', 'type');
        $cat = $user->categories()->get()->keyBy(fn ($c) => $c->type.':'.$c->name);

        $user->accounts()->where('type', 'bank')->update(['initial_balance' => 12_500_000]);
        $user->accounts()->where('type', 'cash')->update(['initial_balance' => 650_000]);
        $user->accounts()->where('type', 'ewallet')->update(['initial_balance' => 2_400_000]);

        $notes = [
            'Makan & Minum' => ['Nasi padang', 'Kopi susu', 'Makan siang kantor', 'Martabak', 'Bakso', 'Sarapan bubur'],
            'Transportasi' => ['Gojek ke kantor', 'Bensin', 'KRL', 'Parkir', 'Grab pulang'],
            'Belanja' => ['Indomaret', 'Sabun & sampo', 'Kaos', 'Belanja mingguan'],
            'Hiburan' => ['Nonton bioskop', 'Spotify', 'Netflix'],
            'Pulsa & Internet' => ['Paket data', 'Wifi rumah'],
        ];
        $ranges = [
            'Makan & Minum' => [15_000, 85_000],
            'Transportasi' => [10_000, 60_000],
            'Belanja' => [25_000, 350_000],
            'Hiburan' => [45_000, 120_000],
            'Pulsa & Internet' => [50_000, 350_000],
        ];

        $today = CarbonImmutable::today();
        $start = $today->subMonths(2)->startOfMonth();

        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            if ($day->day === 25) {
                $this->add($user, 'income', 8_500_000, $accounts['bank'], $cat['income:Gaji']->id, 'Gaji bulanan', $day);
                $this->add($user, 'expense', 1_750_000, $accounts['bank'], $cat['expense:Rumah']->id, 'Sewa kos', $day);
                $this->add($user, 'transfer', 1_800_000, $accounts['bank'], null, 'Isi saldo', $day, $accounts['ewallet']);
            }
            if (in_array($day->day, [1, 15], true)) {
                $this->add($user, 'transfer', 1_000_000, $accounts['bank'], null, 'Tarik tunai', $day, $accounts['cash']);
            }
            if ($day->day === 3) {
                $this->add($user, 'expense', 420_000, $accounts['bank'], $cat['expense:Tagihan']->id, 'Listrik', $day);
            }

            foreach ((array) array_rand($notes, random_int(1, 3)) as $name) {
                [$min, $max] = $ranges[$name];
                $amount = (int) (round(random_int($min, $max) / 500) * 500);
                $account = $name === 'Transportasi' ? $accounts['ewallet'] : $accounts[['cash', 'bank', 'ewallet'][random_int(0, 2)]];
                $note = $notes[$name][array_rand($notes[$name])];
                $this->add($user, 'expense', $amount, $account, $cat['expense:'.$name]->id, $note, $day);
            }
        }

        $this->add($user, 'income', 750_000, $accounts['bank'], $cat['income:Usaha']->id, 'Jual barang bekas', $today->subDays(9));
    }

    private function add(User $user, string $type, int $amount, int $account, ?int $category, string $note, CarbonImmutable $day, ?int $to = null): void
    {
        $user->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'account_id' => $account,
            'to_account_id' => $to,
            'category_id' => $category,
            'note' => $note,
            'occurred_on' => $day->toDateString(),
        ]);
    }
}
