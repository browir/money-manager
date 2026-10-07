<?php

namespace Tests\Feature;

use App\Models\Recurring;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecurringAndOfflineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $account;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->account = $this->user->accounts()->create(['name' => 'Bank', 'type' => 'bank', 'color' => 'ink', 'initial_balance' => 0, 'sort' => 0])->id;
        $this->category = $this->user->categories()->create(['name' => 'Tagihan', 'type' => 'expense', 'icon' => 'receipt', 'color' => 'ink', 'sort' => 0])->id;
    }

    private function schedule(array $overrides = []): Recurring
    {
        return $this->user->recurrings()->create([
            'type' => 'expense', 'amount' => 150000, 'account_id' => $this->account, 'category_id' => $this->category,
            'note' => 'Internet', 'frequency' => 'monthly', 'start_on' => '2026-01-31', 'next_due' => '2026-01-31',
            ...$overrides,
        ]);
    }

    public function test_jadwal_bulanan_tidak_bergeser_dari_tanggal_jangkar(): void
    {
        $r = $this->schedule();
        $dates = [];
        for ($i = 0; $i < 4; $i++) {
            $r->advance();
            $dates[] = $r->next_due->toDateString();
        }
        // 31 Jan → 28 Feb → 31 Mar (kembali ke tgl 31) → 30 Apr → 31 Mei
        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'], $dates);

        $weekly = $this->schedule(['frequency' => 'weekly', 'start_on' => '2026-10-05', 'next_due' => '2026-10-05']);
        $this->assertSame('2026-10-12', $weekly->following($weekly->next_due)->toDateString());

        $yearly = $this->schedule(['frequency' => 'yearly', 'start_on' => '2028-02-29', 'next_due' => '2028-02-29']);
        $this->assertSame('2029-02-28', $yearly->following($yearly->next_due)->toDateString());
    }

    public function test_membuat_jadwal_dan_tampil_di_beranda_saat_jatuh_tempo(): void
    {
        $this->actingAs($this->user)->post(route('recurring.store'), [
            'type' => 'expense', 'amount' => 150000, 'account_id' => $this->account, 'category_id' => $this->category,
            'note' => 'Internet', 'frequency' => 'monthly', 'next_due' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $r = $this->user->recurrings()->first();
        $this->assertSame(now()->toDateString(), $r->start_on->toDateString());

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('due', 1)->where('due.0.id', $r->id));

        // Belum jatuh tempo → tidak tampil.
        $r->update(['next_due' => now()->addDays(3)->toDateString()]);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('due', 0));
    }

    public function test_catat_dan_lewati_memajukan_jadwal(): void
    {
        $r = $this->schedule(['start_on' => '2026-10-05', 'next_due' => '2026-10-05']);

        $this->actingAs($this->user)->post(route('recurring.record', $r));
        $t = $this->user->transactions()->first();
        $this->assertSame(150000, $t->amount);
        $this->assertSame('2026-10-05', $t->occurred_on->format('Y-m-d')); // tanggal jatuh tempo, bukan hari ini
        $this->assertSame('2026-11-05', $r->fresh()->next_due->toDateString());

        $this->post(route('recurring.skip', $r));
        $this->assertSame('2026-12-05', $r->fresh()->next_due->toDateString());
        $this->assertSame(1, $this->user->transactions()->count());
    }

    public function test_simpan_dari_form_dengan_recurring_id_ikut_memajukan_jadwal(): void
    {
        $r = $this->schedule(['start_on' => '2026-10-05', 'next_due' => '2026-10-05']);

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => 175000, 'account_id' => $this->account, 'category_id' => $this->category,
            'note' => 'Internet', 'occurred_on' => '2026-10-05', 'recurring_id' => $r->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(175000, $this->user->transactions()->first()->amount);
        $this->assertSame('2026-11-05', $r->fresh()->next_due->toDateString());
    }

    public function test_jadwal_milik_orang_lain_tidak_bisa_disentuh(): void
    {
        $r = $this->schedule();
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('recurring.record', $r))->assertNotFound();
        $this->actingAs($other)->delete(route('recurring.destroy', $r))->assertNotFound();
        $this->assertSame(0, $this->user->transactions()->count());
    }

    public function test_antrean_offline_json_dan_tidak_dobel_saat_dikirim_ulang(): void
    {
        $payload = [
            'client_id' => (string) Str::uuid(), 'type' => 'expense', 'amount' => 20000, 'account_id' => $this->account,
            'category_id' => $this->category, 'note' => 'Parkir', 'occurred_on' => CarbonImmutable::now()->toDateString(),
        ];

        $first = $this->actingAs($this->user)->postJson(route('transactions.store'), $payload)->assertCreated();
        $again = $this->postJson(route('transactions.store'), $payload)->assertCreated();

        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, $this->user->transactions()->count());

        // Data tidak valid tetap dijawab 422 JSON (ditampilkan "perlu diperbaiki" di HP).
        $this->postJson(route('transactions.store'), [...$payload, 'client_id' => (string) Str::uuid(), 'account_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }

    public function test_antrean_offline_tanpa_sesi_dijawab_401(): void
    {
        $this->postJson(route('transactions.store'), ['type' => 'expense'])->assertUnauthorized();
    }
}
