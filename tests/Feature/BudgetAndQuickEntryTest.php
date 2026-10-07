<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BudgetAndQuickEntryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create();
        $account = $user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);
        $food = $user->categories()->create(['name' => 'Makan', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'sort' => 0]);
        $salary = $user->categories()->create(['name' => 'Gaji', 'type' => 'income', 'icon' => 'wallet', 'color' => 'moss', 'sort' => 0]);

        return [$user, $account, $food, $salary];
    }

    public function test_anggaran_disimpan_hanya_untuk_pengeluaran(): void
    {
        [$user, , $food, $salary] = $this->owner();

        $this->actingAs($user)->put(route('categories.update', $food), [
            'name' => 'Makan', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'budget' => 1500000,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1500000, $food->fresh()->budget);

        // 0 = tanpa anggaran.
        $this->put(route('categories.update', $food), [
            'name' => 'Makan', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'budget' => 0,
        ]);
        $this->assertNull($food->fresh()->budget);

        // Kategori pemasukan tidak punya anggaran.
        $this->put(route('categories.update', $salary), [
            'name' => 'Gaji', 'type' => 'income', 'icon' => 'wallet', 'color' => 'moss', 'budget' => 500000,
        ]);
        $this->assertNull($salary->fresh()->budget);
    }

    public function test_props_bersama_berisi_pemakaian_anggaran_dan_saran_catatan(): void
    {
        [$user, $account, $food] = $this->owner();
        $food->update(['budget' => 100000]);

        $make = fn (int $amount, string $note, string $date) => $user->transactions()->create([
            'type' => 'expense', 'amount' => $amount, 'account_id' => $account->id,
            'category_id' => $food->id, 'note' => $note, 'occurred_on' => $date,
        ]);
        $make(30000, 'Kopi', now()->toDateString());
        $make(20000, 'Kopi', now()->toDateString());
        $make(99000, 'Makan malam', now()->subMonthNoOverflow()->startOfMonth()->toDateString()); // bulan lalu

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.id', $food->id)
            ->where('categories.0.budget', 100000)
            ->where('categories.0.spent', 50000) // hanya bulan berjalan
            ->where('categories.0.uses', 3)
            ->where('noteSuggestions.0.note', 'Kopi') // tersering di depan
            ->has('noteSuggestions', 2)
        );
    }

    public function test_pencarian_tidak_peka_huruf_dan_mencakup_nama_kategori(): void
    {
        [$user, $account, $food] = $this->owner();
        $user->transactions()->create([
            'type' => 'expense', 'amount' => 25000, 'account_id' => $account->id,
            'category_id' => $food->id, 'note' => 'Nasi Padang', 'occurred_on' => now()->toDateString(),
        ]);

        foreach (['nasi', 'PADANG', 'makan'] as $q) {
            $this->actingAs($user)->get(route('transactions.index', ['q' => $q]))
                ->assertInertia(fn (Assert $page) => $page->has('transactions', 1));
        }
    }
}
