<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TransactionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Catat lewat suara: jawaban Claude dipalsukan, yang diuji validasi & penyimpanannya. */
class VoiceEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->ids['cash'] = $this->user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0])->id;
        $this->ids['bank'] = $this->user->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'color' => 'ink', 'initial_balance' => 0, 'sort' => 1])->id;
        $this->ids['food'] = $this->user->categories()->create(['name' => 'Makan', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'sort' => 0])->id;
        $this->ids['salary'] = $this->user->categories()->create(['name' => 'Gaji', 'type' => 'income', 'icon' => 'briefcase', 'color' => 'moss', 'sort' => 0])->id;
    }

    /** Ganti panggilan API dengan jawaban tetap. */
    private function fakeClaude(array $answer): void
    {
        $this->app->instance(TransactionParser::class, new class($answer) extends TransactionParser
        {
            public function __construct(private array $answer) {}

            public function enabled(): bool
            {
                return true;
            }

            protected function ask(string $prompt): array
            {
                return $this->answer;
            }
        });
    }

    private function item(array $override = []): array
    {
        return $override + [
            'type' => 'expense', 'amount' => 25000, 'account_id' => null, 'to_account_id' => null,
            'category_id' => null, 'occurred_on' => now()->toDateString(), 'note' => 'Bakso',
        ];
    }

    public function test_tanpa_kunci_api_fitur_suara_mati(): void
    {
        config(['services.anthropic.key' => null, 'services.gemini.key' => null]);

        $this->actingAs($this->user)->postJson(route('transactions.parse'), ['text' => 'bakso goceng'])
            ->assertStatus(503);
    }

    public function test_gemini_mencoba_model_berikutnya_lalu_mengurai_json(): void
    {
        config(['services.gemini.key' => 'kunci-uji', 'services.gemini.models' => ['model-a', 'model-b', 'model-c']]);
        $answer = ['unclear' => null, 'items' => [$this->item(['account_id' => $this->ids['cash'], 'category_id' => $this->ids['food']])]];
        Http::fake([
            '*/models/model-a:generateContent' => Http::response(['error' => ['code' => 404]], 404),
            '*/models/model-b:generateContent' => Http::response(['error' => ['code' => 429]], 429),
            '*/models/model-c:generateContent' => Http::response(['candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => "```json\n".json_encode($answer)."\n```"]]],
            ]]]),
        ]);

        $items = $this->actingAs($this->user)->postJson(route('transactions.parse'), ['text' => 'bakso 25 ribu pakai cash'])
            ->assertOk()->json('items');

        $this->assertSame(25000, $items[0]['amount']);
        $this->assertSame($this->ids['food'], $items[0]['category_id']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'kunci-uji')
            && $r['generationConfig']['responseMimeType'] === 'application/json'
            && $r['generationConfig']['responseSchema']['type'] === 'OBJECT'
            && str_contains($r['contents'][0]['parts'][0]['text'], 'bakso 25 ribu'));
    }

    public function test_gemini_kunci_salah_dan_kuota_habis_diberi_pesan_jelas(): void
    {
        config(['services.gemini.key' => 'salah', 'services.gemini.models' => ['model-a']]);
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 400, 'message' => 'API key not valid.', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400)
            ->push([], 429)]);

        $this->actingAs($this->user)->postJson(route('transactions.parse'), ['text' => 'bakso'])
            ->assertStatus(502)->assertJsonPath('message', 'Kunci API Gemini tidak valid. Periksa GEMINI_API_KEY di server.');

        $this->postJson(route('transactions.parse'), ['text' => 'bakso'])
            ->assertStatus(502)->assertJsonPath('message', 'Kuota Gemini habis untuk saat ini. Coba lagi nanti, atau catat manual dulu.');
    }

    public function test_draf_dibersihkan_dari_id_dan_nilai_tidak_valid(): void
    {
        $stranger = User::factory()->create();
        $foreignAccount = $stranger->accounts()->create(['name' => 'X', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);

        $this->fakeClaude(['unclear' => null, 'items' => [
            // Akun milik orang lain + kategori pemasukan untuk pengeluaran -> diganti/dibuang.
            $this->item(['account_id' => $foreignAccount->id, 'category_id' => $this->ids['salary']]),
            // Nominal tidak disebut, tanggal masa depan.
            $this->item(['amount' => 0, 'occurred_on' => now()->addDays(3)->toDateString(), 'category_id' => $this->ids['food']]),
            // Transfer ke akun yang sama.
            $this->item(['type' => 'transfer', 'account_id' => $this->ids['bank'], 'to_account_id' => $this->ids['bank']]),
            // Tanggal ngawur.
            $this->item(['type' => 'income', 'category_id' => $this->ids['salary'], 'occurred_on' => '2026-02-31', 'account_id' => $this->ids['bank']]),
        ]]);

        $items = $this->actingAs($this->user)
            ->postJson(route('transactions.parse'), ['text' => 'contoh', 'default_account_id' => $this->ids['bank']])
            ->assertOk()
            ->json('items');

        $this->assertSame($this->ids['bank'], $items[0]['account_id']); // akun bawaan dari klien
        $this->assertNull($items[0]['category_id']);
        $this->assertSame([], $items[0]['issues']);
        $this->assertNotEmpty($items[0]['notes']);

        $this->assertSame(['Nominal tidak terdengar'], $items[1]['issues']);
        $this->assertSame(now()->toDateString(), $items[1]['occurred_on']);
        $this->assertSame($this->ids['food'], $items[1]['category_id']);

        $this->assertNull($items[2]['to_account_id']);
        $this->assertSame(['Akun tujuan belum jelas'], $items[2]['issues']);

        $this->assertSame(now()->toDateString(), $items[3]['occurred_on']);
        $this->assertSame($this->ids['salary'], $items[3]['category_id']);

        // Mengurai tidak menyimpan apa pun.
        $this->assertSame(0, $this->user->transactions()->count());
    }

    public function test_simpan_banyak_menyimpan_semua_draf(): void
    {
        $this->actingAs($this->user)->post(route('transactions.storeMany'), ['items' => [
            $this->item(['account_id' => $this->ids['cash'], 'category_id' => $this->ids['food']]),
            $this->item(['type' => 'income', 'amount' => 5000000, 'account_id' => $this->ids['bank'], 'category_id' => $this->ids['salary'], 'note' => 'Gaji']),
            $this->item(['type' => 'transfer', 'amount' => 200000, 'account_id' => $this->ids['bank'], 'to_account_id' => $this->ids['cash'], 'category_id' => $this->ids['food'], 'note' => 'Tarik tunai']),
        ]])->assertSessionHasNoErrors();

        $this->assertSame(3, $this->user->transactions()->count());
        // Transfer tidak berkategori walau dikirim dengan kategori.
        $this->assertNull($this->user->transactions()->where('type', 'transfer')->value('category_id'));
    }

    public function test_simpan_banyak_menolak_semua_bila_satu_tidak_valid(): void
    {
        $stranger = User::factory()->create();
        $foreign = $stranger->accounts()->create(['name' => 'X', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);

        $this->actingAs($this->user)->post(route('transactions.storeMany'), ['items' => [
            $this->item(['account_id' => $this->ids['cash']]),
            $this->item(['account_id' => $foreign->id]),
            $this->item(['account_id' => $this->ids['cash'], 'amount' => 0]),
            $this->item(['account_id' => $this->ids['cash'], 'occurred_on' => now()->addDay()->toDateString()]),
        ]])->assertSessionHasErrors(['items.1.account_id', 'items.2.amount', 'items.3.occurred_on']);

        $this->assertSame(0, $this->user->transactions()->count());
    }
}
