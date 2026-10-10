<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PeriodAndFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $date): void
    {
        Carbon::setTestNow($date);
        CarbonImmutable::setTestNow($date);
    }

    public function test_periode_mengikuti_tanggal_gajian(): void
    {
        $this->freeze('2026-10-10');

        // Awal tanggal 1 = bulan kalender.
        $p = Period::fromKey(null, 1);
        $this->assertSame(['2026-10-01', '2026-10-31'], $p->range());

        // Awal tanggal 25: tanggal 10 Okt masih periode yang dimulai 25 Sep.
        $p = Period::fromKey(null, 25);
        $this->assertSame('2026-09', $p->key());
        $this->assertSame(['2026-09-25', '2026-10-24'], $p->range());
        $this->assertSame(['2026-08-25', '2026-09-24'], $p->previous()->range());
        $this->assertCount(30, $p->days());

        // Kunci eksplisit & melintasi tahun.
        $this->assertSame(['2026-12-25', '2027-01-24'], Period::fromKey('2026-12', 25)->range());
        // Februari: tanggal 28 tetap aman.
        $this->assertSame(['2027-02-28', '2027-03-27'], Period::fromKey('2027-02', 28)->range());
    }

    public function test_pengaturan_awal_periode_dan_dampaknya_ke_beranda(): void
    {
        $this->freeze('2026-10-10');
        $user = User::factory()->create();
        $account = $user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);
        $make = fn (string $date, int $amount) => $user->transactions()->create([
            'type' => 'expense', 'amount' => $amount, 'account_id' => $account->id, 'occurred_on' => $date,
        ]);
        $make('2026-09-20', 1000); // periode sebelumnya
        $make('2026-09-26', 2000);
        $make('2026-10-10', 3000);

        $this->actingAs($user)->put(route('settings.period'), ['period_start' => 29])->assertSessionHasErrors('period_start');
        $this->put(route('settings.period'), ['period_start' => 25])->assertSessionHasNoErrors();
        $this->assertSame(25, $user->fresh()->period_start);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('month', '2026-09')
            ->where('period.startDay', 25)
            ->where('period.start', '2026-09-25')
            ->where('totals.expense', 5000)
            ->where('totals.prevExpense', 1000)
            ->has('daily', 30)
            ->where('daily.0.date', '2026-09-25')
            ->where('daily.1.expense', 2000)
        );
    }

    public function test_saringan_rentang_semua_waktu_dan_ringkasan(): void
    {
        $this->freeze('2026-10-10');
        $user = User::factory()->create();
        $account = $user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);
        $make = fn (string $type, string $date, int $amount, string $note) => $user->transactions()->create([
            'type' => $type, 'amount' => $amount, 'account_id' => $account->id, 'occurred_on' => $date, 'note' => $note,
        ]);
        $make('expense', '2026-06-03', 50000, 'Servis motor');
        $make('expense', '2026-10-02', 20000, 'Servis jam');
        $make('income', '2026-10-05', 90000, 'Bonus');

        $this->actingAs($user);

        // Bawaan: periode berjalan saja.
        $this->get(route('transactions.index', ['q' => 'servis']))->assertInertia(fn (Assert $page) => $page
            ->where('range.mode', 'period')
            ->has('transactions', 1)
        );

        // Semua waktu.
        $this->get(route('transactions.index', ['q' => 'servis', 'all' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('range.mode', 'all')
            ->has('transactions', 2)
            ->where('totals.expense', 70000)
        );

        // Rentang tanggal (urutan terbalik ditukar otomatis).
        $this->get(route('transactions.index', ['from' => '2026-10-31', 'to' => '2026-10-01']))->assertInertia(fn (Assert $page) => $page
            ->where('range', ['mode' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->where('totals', ['count' => 2, 'income' => 90000, 'expense' => 20000])
        );
    }
}
