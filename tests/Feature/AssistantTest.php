<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Support\VoiceAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Asisten suara: jawaban AI dipalsukan, yang diuji pembersihan, validasi & penjalanannya. */
class AssistantTest extends TestCase
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
    private function fakeAi(array $answer): void
    {
        $answer += ['reply' => null, 'unclear' => null, 'items' => [], 'account_actions' => []];
        $this->app->instance(VoiceAssistant::class, new class($answer) extends VoiceAssistant
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

        $this->actingAs($this->user)->postJson(route('assistant.parse'), ['text' => 'bakso goceng'])
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

        $items = $this->actingAs($this->user)->postJson(route('assistant.parse'), ['text' => 'bakso 25 ribu pakai cash'])
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

        $this->actingAs($this->user)->postJson(route('assistant.parse'), ['text' => 'bakso'])
            ->assertStatus(502)->assertJsonPath('message', 'Kunci API Gemini tidak valid. Periksa GEMINI_API_KEY di server.');

        $this->postJson(route('assistant.parse'), ['text' => 'bakso'])
            ->assertStatus(502)->assertJsonPath('message', 'Kuota Gemini habis untuk saat ini. Coba lagi nanti, atau catat manual dulu.');
    }

    public function test_draf_dibersihkan_dari_id_dan_nilai_tidak_valid(): void
    {
        $stranger = User::factory()->create();
        $foreignAccount = $stranger->accounts()->create(['name' => 'X', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);

        $this->fakeAi(['unclear' => null, 'items' => [
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
            ->postJson(route('assistant.parse'), ['text' => 'contoh', 'default_account_id' => $this->ids['bank']])
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

    public function test_jalankan_menyimpan_semua_draf(): void
    {
        $this->actingAs($this->user)->post(route('assistant.apply'), ['items' => [
            $this->item(['account_id' => $this->ids['cash'], 'category_id' => $this->ids['food']]),
            $this->item(['type' => 'income', 'amount' => 5000000, 'account_id' => $this->ids['bank'], 'category_id' => $this->ids['salary'], 'note' => 'Gaji']),
            $this->item(['type' => 'transfer', 'amount' => 200000, 'account_id' => $this->ids['bank'], 'to_account_id' => $this->ids['cash'], 'category_id' => $this->ids['food'], 'note' => 'Tarik tunai']),
        ]])->assertSessionHasNoErrors();

        $this->assertSame(3, $this->user->transactions()->count());
        // Transfer tidak berkategori walau dikirim dengan kategori.
        $this->assertNull($this->user->transactions()->where('type', 'transfer')->value('category_id'));
    }

    public function test_jalankan_menolak_semua_bila_satu_tidak_valid(): void
    {
        $stranger = User::factory()->create();
        $foreign = $stranger->accounts()->create(['name' => 'X', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);

        $this->actingAs($this->user)->post(route('assistant.apply'), ['items' => [
            $this->item(['account_id' => $this->ids['cash']]),
            $this->item(['account_id' => $foreign->id]),
            $this->item(['account_id' => $this->ids['cash'], 'amount' => 0]),
            $this->item(['account_id' => $this->ids['cash'], 'occurred_on' => now()->addDay()->toDateString()]),
        ]])->assertSessionHasErrors(['items.1.account_id', 'items.2.amount', 'items.3.occurred_on']);

        $this->assertSame(0, $this->user->transactions()->count());
    }

    private function balance(int $accountId): int
    {
        return (int) Account::withBalance()->whereKey($accountId)->value('balance');
    }

    public function test_usulan_akun_dibersihkan(): void
    {
        $this->user->accounts()->create(['name' => 'Lama', 'type' => 'bank', 'color' => 'ink', 'initial_balance' => 0, 'sort' => 2, 'archived_at' => now()]);
        $act = fn (array $a) => $a + ['account_id' => null, 'name' => null, 'type' => null, 'amount' => null, 'note' => null];
        $this->fakeAi(['reply' => 'Saldo BCA Rp 0.', 'encouragement' => 'Mantap, rajin mencatat! Saldomu tinggal Rp 5.000. Sisihkan dua ratus ribu ya.', 'account_actions' => [
            $act(['action' => 'create', 'name' => 'Jago', 'type' => 'bank', 'amount' => 500000]),
            $act(['action' => 'create', 'name' => 'bca', 'type' => 'bank']), // sudah ada
            $act(['action' => 'create', 'name' => 'Dompet', 'type' => 'aneh']),
            $act(['action' => 'adjust_balance', 'account_id' => $this->ids['cash'], 'amount' => 300000]),
            $act(['action' => 'adjust_balance', 'account_id' => $this->ids['bank'], 'amount' => 0]), // sudah sesuai
            $act(['action' => 'archive', 'account_id' => 999999]), // tidak ada
            $act(['action' => 'hapus', 'account_id' => $this->ids['cash']]), // tidak didukung
        ]]);

        $body = $this->actingAs($this->user)->postJson(route('assistant.parse'), ['text' => 'contoh'])->assertOk()->json();
        $actions = $body['account_actions'];

        $this->assertSame('Saldo BCA Rp 0.', $body['reply']);
        // Yang diucapkan tidak boleh berisi nominal: kalimat beranggka dibuang.
        $this->assertSame('Mantap, rajin mencatat!', $body['encouragement']);
        $this->assertCount(4, $actions);
        $this->assertSame([], $actions[0]['issues']);
        $this->assertSame(500000, $actions[0]['amount']);
        $this->assertSame(['Akun bernama bca sudah ada'], $actions[1]['issues']);
        $this->assertSame('other', $actions[2]['type']);
        $this->assertSame(0, $actions[2]['amount']);
        $this->assertSame('adjust_balance', $actions[3]['action']);
        $this->assertSame(0, $actions[3]['current_balance']);
        $this->assertStringContainsString('sudah Rp 0', $body['unclear']);
        $this->assertStringContainsString('tidak ditemukan', $body['unclear']);
        $this->assertSame(0, $this->user->accounts()->where('name', 'Jago')->count()); // belum ada yang disimpan
    }

    public function test_jalankan_aksi_akun_berurutan(): void
    {
        $old = $this->user->accounts()->create(['name' => 'Lama', 'type' => 'bank', 'color' => 'ink', 'initial_balance' => 0, 'sort' => 2, 'archived_at' => now()]);

        $this->actingAs($this->user)->post(route('assistant.apply'), [
            'items' => [$this->item(['account_id' => $this->ids['cash'], 'amount' => 25000])],
            'account_actions' => [
                // Saldo dihitung SETELAH transaksi di atas: hasil akhir tepat 300rb.
                ['action' => 'adjust_balance', 'account_id' => $this->ids['cash'], 'amount' => 300000, 'note' => 'Hitung dompet'],
                ['action' => 'create', 'name' => 'Jago', 'type' => 'bank', 'amount' => 500000],
                ['action' => 'rename', 'account_id' => $this->ids['bank'], 'name' => 'BCA Utama'],
                ['action' => 'unarchive', 'account_id' => $old->id],
                ['action' => 'archive', 'account_id' => $this->ids['bank']],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(300000, $this->balance($this->ids['cash']));
        $this->assertSame(325000, $this->user->transactions()->where('type', 'adjustment')->value('amount'));
        $jago = $this->user->accounts()->where('name', 'Jago')->firstOrFail();
        $this->assertSame(['bank', 'ink', 500000], [$jago->type, $jago->color, $this->balance($jago->id)]);
        $bank = $this->user->accounts()->find($this->ids['bank']);
        $this->assertSame('BCA Utama', $bank->name);
        $this->assertNotNull($bank->archived_at);
        $this->assertNull($old->fresh()->archived_at);
    }

    public function test_jalankan_aksi_akun_tidak_valid_membatalkan_semua(): void
    {
        $foreign = User::factory()->create()->accounts()->create(['name' => 'X', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);

        $this->actingAs($this->user)->post(route('assistant.apply'), [
            'items' => [$this->item(['account_id' => $this->ids['cash']])],
            'account_actions' => [
                ['action' => 'create', 'name' => 'Jago', 'type' => 'bank'],
                ['action' => 'rename', 'account_id' => $foreign->id, 'name' => 'Curian'],
                ['action' => 'adjust_balance', 'account_id' => $this->ids['cash']],
                ['action' => 'create', 'name' => '', 'type' => 'planet'],
                ['action' => 'delete', 'account_id' => $this->ids['cash']],
            ],
        ])->assertSessionHasErrors([
            'account_actions.1.account_id', 'account_actions.2.amount',
            'account_actions.3.name', 'account_actions.3.type', 'account_actions.4.action',
        ]);

        $this->assertSame(0, $this->user->transactions()->count());
        $this->assertSame(0, $this->user->accounts()->where('name', 'Jago')->count());
        $this->assertSame('X', $foreign->fresh()->name);
    }

    public function test_jalankan_tanpa_isi_ditolak(): void
    {
        $this->actingAs($this->user)->post(route('assistant.apply'), [])->assertSessionHasErrors('items');
    }

    public function test_suara_gemini_dibungkus_wav_dan_jatuh_ke_model_berikutnya(): void
    {
        config(['services.gemini.key' => 'kunci-uji', 'services.gemini.tts_models' => ['tts-a', 'tts-b']]);
        $pcm = str_repeat("\x01\x00", 2400);
        Http::fake([
            '*/models/tts-a:generateContent' => Http::response([], 404),
            '*/models/tts-b:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [[
                'inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)],
            ]]]]]]),
        ]);

        $response = $this->actingAs($this->user)->post(route('assistant.speak'), ['text' => 'Beres! Pengeluaran sudah dicatat.', 'voice' => 'Kore'])
            ->assertOk()->assertHeader('Content-Type', 'audio/wav');

        $wav = $response->getContent();
        $this->assertSame('RIFF', substr($wav, 0, 4));
        $this->assertSame(44 + strlen($pcm), strlen($wav));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tts-b')
            && $r['generationConfig']['responseModalities'] === ['AUDIO']
            && $r['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore');
    }

    public function test_suara_gemini_menolak_suara_asing_dan_tanpa_kunci(): void
    {
        config(['services.gemini.key' => 'kunci-uji']);
        $this->actingAs($this->user)->postJson(route('assistant.speak'), ['text' => 'Halo', 'voice' => 'Bukan'])->assertStatus(422);

        config(['services.gemini.key' => null]);
        $this->postJson(route('assistant.speak'), ['text' => 'Halo'])->assertStatus(503);
    }

    public function test_nominal_hanya_diucapkan_bila_diminta(): void
    {
        $this->fakeAi(['reply' => 'Saldo BCA Rp 2.000.000.', 'speak_amounts' => true]);
        // Model menandai true, tapi ucapan tidak meminta dibacakan -> tetap false.
        $this->actingAs($this->user)->postJson(route('assistant.parse'), ['text' => 'berapa saldo BCA?'])
            ->assertOk()->assertJsonPath('speak_amounts', false);
        // Diminta eksplisit -> boleh.
        $this->postJson(route('assistant.parse'), ['text' => 'tolong sebutkan saldo BCA'])->assertJsonPath('speak_amounts', true);
        $this->postJson(route('assistant.parse'), ['text' => 'kasih tahu nominalnya dong'])->assertJsonPath('speak_amounts', true);

        $this->fakeAi(['reply' => 'Saldo BCA Rp 2.000.000.', 'speak_amounts' => false]);
        $this->postJson(route('assistant.parse'), ['text' => 'bacakan saldo BCA'])->assertJsonPath('speak_amounts', false);
    }

    public function test_suara_gemini_coba_teks_polos_dan_lapor_kuota_habis(): void
    {
        config(['services.gemini.key' => 'kunci-uji', 'services.gemini.tts_models' => ['tts-a']]);
        Http::fake(['*' => Http::sequence()
            // Arahan gaya ditolak -> diulang dengan teks polos -> berhasil.
            ->push(['error' => ['code' => 400, 'message' => 'Model tried to generate text']], 400)
            ->push(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode("\0\0")]]]]]]])
            // Permintaan kedua: kuota habis.
            ->push(['error' => ['code' => 429]], 429)]);

        $this->actingAs($this->user)->post(route('assistant.speak'), ['text' => 'Siap, Tuan.'])->assertOk()->assertHeader('Content-Type', 'audio/wav');
        Http::assertSent(fn (Request $r) => $r['contents'][0]['parts'][0]['text'] === 'Siap, Tuan.');

        $this->postJson(route('assistant.speak'), ['text' => 'Halo, Tuan.'])
            ->assertStatus(429)->assertJsonPath('message', 'Kuota suara Gemini hari ini habis.');
    }
}
