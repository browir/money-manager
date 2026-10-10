<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UrgentCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return $override + ['name' => 'Darurat', 'type' => 'expense', 'icon' => 'siren', 'color' => 'rose'];
    }

    public function test_kategori_darurat_tidak_punya_anggaran(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('categories.store'), $this->payload(['urgent' => true, 'budget' => 500000]))
            ->assertSessionHasNoErrors();

        $category = $user->categories()->firstOrFail();
        $this->assertTrue($category->urgent);
        $this->assertNull($category->budget);

        // Tanda darurat dilepas: anggaran bisa diisi lagi.
        $this->put(route('categories.update', $category), $this->payload(['urgent' => false, 'budget' => 500000]));
        $this->assertFalse($category->fresh()->urgent);
        $this->assertSame(500000, $category->fresh()->budget);
    }

    public function test_kategori_pemasukan_tidak_bisa_darurat(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('categories.store'), $this->payload(['name' => 'Bonus', 'type' => 'income', 'urgent' => true]));

        $this->assertFalse($user->categories()->firstOrFail()->urgent);
    }

    public function test_props_bersama_menandai_kategori_darurat(): void
    {
        $user = User::factory()->create();
        $account = $user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);
        $urgent = $user->categories()->create($this->payload(['urgent' => true, 'sort' => 0]));
        $user->transactions()->create([
            'type' => 'expense', 'amount' => 750000, 'account_id' => $account->id,
            'category_id' => $urgent->id, 'occurred_on' => now()->toDateString(),
        ]);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.urgent', true)
            ->where('categories.0.budget', null)
            ->where('categories.0.spent', 750000)
            // Tetap tercatat sebagai pengeluaran di arus kas.
            ->where('totals.expense', 750000)
        );
    }
}
