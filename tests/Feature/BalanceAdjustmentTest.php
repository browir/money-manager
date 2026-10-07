<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BalanceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->bank = $this->user->accounts()->create(['name' => 'Bank', 'type' => 'bank', 'color' => 'ink', 'initial_balance' => 1000000, 'sort' => 0]);
        $food = $this->user->categories()->create(['name' => 'Makan', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'sort' => 0]);
        $this->user->transactions()->create([
            'type' => 'expense', 'amount' => 200000, 'account_id' => $this->bank->id,
            'category_id' => $food->id, 'occurred_on' => now()->toDateString(),
        ]);
    }

    private function balance(): int
    {
        return (int) Account::withBalance()->whereKey($this->bank->id)->value('balance');
    }

    public function test_menyesuaikan_saldo_turun_dan_naik(): void
    {
        $this->assertSame(800000, $this->balance());

        // Turun: biaya admin yang lupa dicatat.
        $this->actingAs($this->user)->post(route('accounts.adjust', $this->bank), ['balance' => 785000, 'note' => 'Biaya admin'])
            ->assertSessionHasNoErrors();
        $this->assertSame(785000, $this->balance());

        $adj = $this->user->transactions()->where('type', Transaction::ADJUSTMENT)->first();
        $this->assertSame(-15000, $adj->amount);
        $this->assertSame('Biaya admin', $adj->note);

        // Naik.
        $this->post(route('accounts.adjust', $this->bank), ['balance' => 900000]);
        $this->assertSame(900000, $this->balance());
        $this->assertSame(2, $this->user->transactions()->where('type', Transaction::ADJUSTMENT)->count());

        // Saldo awal tidak berubah.
        $this->assertSame(1000000, $this->bank->fresh()->initial_balance);
    }

    public function test_saldo_sama_tidak_membuat_transaksi(): void
    {
        $this->actingAs($this->user)->post(route('accounts.adjust', $this->bank), ['balance' => 800000]);
        $this->assertSame(0, $this->user->transactions()->where('type', Transaction::ADJUSTMENT)->count());
    }

    public function test_penyesuaian_tidak_masuk_arus_kas_dan_anggaran(): void
    {
        $this->actingAs($this->user)->post(route('accounts.adjust', $this->bank), ['balance' => 500000]);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('totals.expense', 200000) // hanya pengeluaran asli
            ->where('totals.income', 0)
            ->has('byCategory', 1)
            ->where('categories.0.spent', 200000)
        );
    }

    public function test_menghapus_penyesuaian_mengembalikan_saldo_dan_tidak_bisa_diubah_lewat_form(): void
    {
        $this->actingAs($this->user)->post(route('accounts.adjust', $this->bank), ['balance' => 700000]);
        $adj = $this->user->transactions()->where('type', Transaction::ADJUSTMENT)->first();

        $this->put(route('transactions.update', $adj), [
            'type' => 'income', 'amount' => 5, 'account_id' => $this->bank->id, 'occurred_on' => now()->toDateString(),
        ]);
        $this->assertSame(Transaction::ADJUSTMENT, $adj->fresh()->type);

        $this->delete(route('transactions.destroy', $adj))->assertSessionHasNoErrors();
        $this->assertSame(800000, $this->balance());
    }

    public function test_tidak_bisa_menyesuaikan_akun_orang_lain_atau_membuat_penyesuaian_lewat_form(): void
    {
        $this->actingAs(User::factory()->create())->post(route('accounts.adjust', $this->bank), ['balance' => 1])->assertNotFound();

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'adjustment', 'amount' => 999, 'account_id' => $this->bank->id, 'occurred_on' => now()->toDateString(),
        ])->assertSessionHasErrors('type');
    }
}
