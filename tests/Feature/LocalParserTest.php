<?php

namespace Tests\Feature;

use App\Models\AssistantExample;
use App\Models\User;
use App\Support\LocalParser;
use App\Support\VoiceAssistant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Pengurai lokal LAX: tanpa panggilan AI, dan tidak pernah menebak nominal. */
class LocalParserTest extends TestCase
{
    use RefreshDatabase;

    private const CASH = 1;

    private const BCA = 2;

    private const GOPAY = 3;

    private const FOOD = 10;

    private const FUEL = 11;

    private const SALARY = 20;

    private const EMERGENCY = 12;

    private Collection $accounts;

    private Collection $categories;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::create(2026, 11, 4); // Rabu
        $this->accounts = collect([
            (object) ['id' => self::CASH, 'name' => 'Tunai', 'type' => 'cash', 'balance' => 300000, 'archived_at' => null],
            (object) ['id' => self::BCA, 'name' => 'BCA', 'type' => 'bank', 'balance' => 2000000, 'archived_at' => null],
            (object) ['id' => self::GOPAY, 'name' => 'GoPay', 'type' => 'ewallet', 'balance' => 50000, 'archived_at' => null],
        ]);
        $this->categories = collect([
            (object) ['id' => self::FOOD, 'name' => 'Makan', 'type' => 'expense', 'urgent' => false, 'budget' => 1000000, 'spent' => 250000],
            (object) ['id' => self::FUEL, 'name' => 'Transportasi', 'type' => 'expense', 'urgent' => false, 'budget' => null, 'spent' => 0],
            (object) ['id' => self::EMERGENCY, 'name' => 'Obat', 'type' => 'expense', 'urgent' => true, 'budget' => null, 'spent' => 0],
            (object) ['id' => self::SALARY, 'name' => 'Gaji', 'type' => 'income', 'urgent' => false, 'budget' => null, 'spent' => 0],
        ]);
    }

    private function parse(string $text, array $training = []): array
    {
        $totals = (object) ['income' => 5000000, 'expense' => 750000];

        return (new LocalParser)->parse($text, $this->accounts, $this->categories, $this->today, $totals, $training);
    }

    public function test_berbagai_cara_menyebut_nominal(): void
    {
        $cases = [
            'bakso 25rb' => 25000,
            'bakso 25 ribu' => 25000,
            'bakso 25k' => 25000,
            'bakso 25.000' => 25000,
            'bakso rp 25.000' => 25000,
            'bakso rp25000' => 25000,
            'bakso 25000' => 25000,
            'servis motor 1,5 jt' => 1500000,
            'servis motor 1.5 juta' => 1500000,
            'servis motor setengah juta' => 500000,
            'servis motor dua setengah juta' => 2500000,
            'servis motor satu koma lima juta' => 1500000,
            'bakso dua puluh lima ribu' => 25000,
            'bakso seratus dua puluh ribu' => 120000,
            'parkir dua ribu lima ratus' => 2500,
            'kopi seribu' => 1000,
            'sewa dua juta lima ratus ribu' => 2500000,
            'sewa 2 juta 500 ribu' => 2500000,
            'sewa 2 juta lima ratus ribu' => 2500000,
            'sewa 2 ratus ribu' => 200000,
            'sewa 10 ribu 500' => 10500,
            'parkir gopek' => 500,
            'kopi goceng' => 5000,
            'kopi 3 ceban' => 30000,
            'makan noban' => 20000,
        ];

        foreach ($cases as $text => $expected) {
            $result = $this->parse($text);
            $this->assertCount(1, $result['items'], $text);
            $this->assertSame($expected, $result['items'][0]['amount'], $text);
        }
    }

    public function test_tidak_mengarang_nominal(): void
    {
        // Jumlah barang bukan nominal.
        $this->assertSame(0, $this->parse('beli 3 kopi')['items'][0]['amount']);
        $this->assertSame(0, $this->parse('beli dua bakso')['items'][0]['amount']);

        // Tanggal & tahun bukan nominal.
        $this->assertSame(0, $this->parse('bayar kos tanggal 3 maret 2026')['items'][0]['amount']);

        // Dua nominal dalam satu klausa: jangan pilih salah satu.
        $two = $this->parse('bayar 50 ribu kembalian 5 ribu');
        $this->assertSame(0, $two['items'][0]['amount']);
        $this->assertNotNull($two['unclear']);

        // Harga satuan belum dihitung.
        $unit = $this->parse('2 kopi masing-masing 10 ribu');
        $this->assertSame(0, $unit['items'][0]['amount']);
        $this->assertNotNull($unit['unclear']);
    }

    public function test_nominal_rancu_tidak_ditebak(): void
    {
        foreach (['makan 1 juta 500', 'servis sejuta lima ratus', 'servis satu juta lima ratus'] as $text) {
            $result = $this->parse($text);
            $this->assertCount(1, $result['items'], $text);
            $this->assertSame(0, $result['items'][0]['amount'], $text);
            $this->assertNotNull($result['unclear'], $text);
        }
    }

    public function test_tanpa_kata_sambung_dipisah_di_nominal(): void
    {
        $items = $this->parse('kemarin bakso 25 ribu kopi 10 ribu pakai bca')['items'];

        $this->assertSame([25000, 10000], array_column($items, 'amount'));
        $this->assertSame(['Bakso', 'Kopi'], array_column($items, 'note'));
        $this->assertSame(['2026-11-03', '2026-11-03'], array_column($items, 'occurred_on'));
        $this->assertSame([self::BCA, self::BCA], array_column($items, 'account_id'));

        // Tanpa nama barang di nominal kedua: jangan pecah, tanyakan.
        $result = $this->parse('bensin 20 ribu 30 ribu');
        $this->assertCount(1, $result['items']);
        $this->assertSame(0, $result['items'][0]['amount']);
        $this->assertNotNull($result['unclear']);
    }

    public function test_jumlah_barang_tidak_mengganggu_nominal(): void
    {
        $item = $this->parse('beli 3 kopi 30 ribu')['items'][0];

        $this->assertSame(30000, $item['amount']);
        $this->assertSame('3 kopi', $item['note']);
    }

    public function test_beberapa_transaksi_dalam_satu_ucapan(): void
    {
        $items = $this->parse('beli bakso 25 ribu dan kopi 10 ribu pakai bca')['items'];

        $this->assertSame([25000, 10000], array_column($items, 'amount'));
        $this->assertSame(['Bakso', 'Kopi'], array_column($items, 'note'));
        $this->assertSame([self::BCA, self::BCA], array_column($items, 'account_id')); // akun berlaku untuk semua
    }

    public function test_kata_sambung_tanpa_nominal_tetap_satu_transaksi(): void
    {
        $items = $this->parse('makan sama teman 50 ribu')['items'];
        $this->assertCount(1, $items);
        $this->assertSame(50000, $items[0]['amount']);

        $items = $this->parse('nasi dan ayam 30 ribu')['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Nasi dan ayam', $items[0]['note']);
    }

    public function test_barang_tanpa_nominal_jadi_usulan_kosong_bukan_digabung(): void
    {
        $items = $this->parse('bakso 25 ribu sama kopi')['items'];

        $this->assertSame([25000, 0], array_column($items, 'amount'));
        $this->assertSame('Kopi', $items[1]['note']);
    }

    public function test_keterangan_di_akhir_tidak_jadi_transaksi_baru(): void
    {
        $items = $this->parse('bakso 25 ribu, pakai tunai')['items'];

        $this->assertCount(1, $items);
        $this->assertSame(self::CASH, $items[0]['account_id']);
        $this->assertSame('Bakso', $items[0]['note']);
    }

    public function test_jenis_transaksi(): void
    {
        $type = fn ($text) => $this->parse($text)['items'][0]['type'];

        $this->assertSame('expense', $type('bakso 25 ribu'));
        $this->assertSame('expense', $type('isi bensin 50 ribu'));
        $this->assertSame('income', $type('gaji oktober 5 juta masuk bca'));
        $this->assertSame('income', $type('dapat bonus 500 ribu'));
        $this->assertSame('transfer', $type('transfer 500 ribu dari bca ke gopay'));
        $this->assertSame('transfer', $type('tarik tunai 200 ribu'));
        $this->assertSame('transfer', $type('top up gopay 100 ribu'));
    }

    public function test_akun_transfer(): void
    {
        $accounts = function ($text) {
            $item = $this->parse($text)['items'][0];

            return [$item['account_id'], $item['to_account_id']];
        };

        $this->assertSame([self::BCA, self::GOPAY], $accounts('transfer 500 ribu dari bca ke gopay'));
        $this->assertSame([self::BCA, self::GOPAY], $accounts('transfer 500 ribu ke gopay dari bca'));
        $this->assertSame([self::BCA, self::CASH], $accounts('tarik tunai 200 ribu'));
        $this->assertSame([self::BCA, self::CASH], $accounts('ambil uang di atm bca 200 ribu'));
        $this->assertSame([self::BCA, self::GOPAY], $accounts('top up gopay 100 ribu dari bca'));
        $this->assertSame([null, self::GOPAY], $accounts('top up gopay 100 ribu')); // sumber dibiarkan untuk ditinjau
    }

    public function test_akun_dari_nama_dan_sebutan(): void
    {
        $account = fn ($text) => $this->parse($text)['items'][0]['account_id'];

        $this->assertSame(self::CASH, $account('bakso 25 ribu pakai cash'));
        $this->assertSame(self::CASH, $account('bakso 25 ribu dari dompet'));
        $this->assertSame(self::BCA, $account('bakso 25 ribu pakai rekening'));
        $this->assertSame(self::GOPAY, $account('bakso 25 ribu lewat gopay'));
        $this->assertNull($account('bakso 25 ribu'));
    }

    public function test_akun_arsip_tidak_dipakai(): void
    {
        $this->accounts = collect([
            (object) ['id' => self::CASH, 'name' => 'Tunai', 'type' => 'cash', 'balance' => 0, 'archived_at' => null],
            (object) ['id' => self::BCA, 'name' => 'BCA', 'type' => 'bank', 'balance' => 0, 'archived_at' => now()],
        ]);

        $this->assertNull($this->parse('bakso 25 ribu pakai bca')['items'][0]['account_id']);
    }

    public function test_tanggal(): void
    {
        $date = fn ($text) => $this->parse($text)['items'][0]['occurred_on'];

        $this->assertSame('2026-11-04', $date('bakso 25 ribu'));
        $this->assertSame('2026-11-04', $date('bakso 25 ribu hari ini'));
        $this->assertSame('2026-11-03', $date('kemarin bakso 25 ribu'));
        $this->assertSame('2026-11-02', $date('kemarin lusa bakso 25 ribu'));
        $this->assertSame('2026-11-01', $date('3 hari lalu bakso 25 ribu'));
        $this->assertSame('2026-10-28', $date('seminggu lalu bakso 25 ribu'));
        $this->assertSame('2026-11-02', $date('senin kemarin bakso 25 ribu'));
        $this->assertSame('2026-10-28', $date('rabu kemarin bakso 25 ribu')); // hari ini Rabu
        $this->assertSame('2026-11-01', $date('minggu kemarin bakso 25 ribu'));
        $this->assertSame('2026-11-03', $date('tanggal 3 bakso 25 ribu'));
        $this->assertSame('2026-10-20', $date('tanggal 20 bakso 25 ribu')); // belum lewat bulan ini
        $this->assertSame('2026-03-03', $date('tanggal 3 maret bakso 25 ribu'));
        $this->assertSame('2025-12-25', $date('tanggal 25 desember bakso 25 ribu'));
        $this->assertSame('', $date('tanggal 31 april bakso 25 ribu')); // sanitize() yang memberi catatan
    }

    public function test_tanggal_berlaku_untuk_semua_transaksi_dalam_ucapan(): void
    {
        $items = $this->parse('kemarin beli bakso 25 ribu dan kopi 10 ribu')['items'];

        $this->assertSame(['2026-11-03', '2026-11-03'], array_column($items, 'occurred_on'));
    }

    public function test_catatan_bersih(): void
    {
        $note = fn ($text) => $this->parse($text)['items'][0]['note'];

        $this->assertSame('Makan siang', $note('Makan siang 25 ribu pakai cash'));
        $this->assertSame('Bensin motor', $note('tolong catat beli bensin motor 50 ribu'));
        $this->assertSame('Listrik', $note('bayar listrik 300 ribu dari bca'));
        $this->assertSame('Gaji oktober', $note('gaji oktober 5 juta masuk bca'));
        $this->assertSame('Tarik tunai', $note('tarik tunai 200 ribu'));
        $this->assertSame('Dikasih ibu', $note('dikasih ibu 200 ribu'));
        $this->assertSame('Transfer', $note('transfer 500 ribu dari bca ke gopay'));
        $this->assertLessThanOrEqual(60, mb_strlen($note('beli '.str_repeat('barang panjang ', 10).'25 ribu')));
    }

    public function test_kategori_dari_nama_dan_hasil_belajar(): void
    {
        $training = [
            ['text' => 'Bakso', 'category_id' => self::FOOD],
            ['text' => 'Bakso urat', 'category_id' => self::FOOD],
            ['text' => 'Nasi padang', 'category_id' => self::FOOD],
            ['text' => 'Bensin motor', 'category_id' => self::FUEL],
            ['text' => 'Bensin mobil', 'category_id' => self::FUEL],
            ['text' => 'Parkir', 'category_id' => self::FUEL],
        ];
        $category = fn ($text) => $this->parse($text, $training)['items'][0]['category_id'];

        $this->assertSame(self::FOOD, $category('makan 25 ribu')); // nama kategori disebut
        $this->assertSame(self::FOOD, $category('bakso 25 ribu'));
        $this->assertSame(self::FUEL, $category('isi bensin 50 ribu'));
        $this->assertSame(self::FUEL, $category('parkir 2 ribu')); // baru muncul sekali di riwayat
        $this->assertSame(self::SALARY, $this->parse('gajian 5 juta')['items'][0]['category_id']); // dekat dengan nama kategori
        $this->assertNull($category('sepatu baru 500 ribu')); // kata asing: jangan menebak
        $this->assertNull($this->parse('bakso 25 ribu')['items'][0]['category_id']); // belum ada data
    }

    public function test_kategori_tidak_ditebak_bila_kurang_yakin(): void
    {
        $training = [
            ['text' => 'Kopi', 'category_id' => self::FOOD],
            ['text' => 'Kopi', 'category_id' => self::FUEL],
        ];

        $this->assertNull($this->parse('kopi 10 ribu', $training)['items'][0]['category_id']);
    }

    public function test_kategori_darurat_hanya_bila_disebut(): void
    {
        $training = [['text' => 'Obat flu', 'category_id' => self::EMERGENCY], ['text' => 'Obat batuk', 'category_id' => self::EMERGENCY]];

        $this->assertNull($this->parse('obat flu 30 ribu', $training)['items'][0]['category_id']);
        $this->assertSame(self::EMERGENCY, $this->parse('obat flu darurat 30 ribu', $training)['items'][0]['category_id']);
    }

    public function test_menjawab_pertanyaan_dari_data(): void
    {
        $reply = fn ($text) => $this->parse($text)['reply'];

        $this->assertSame('Total saldo akun aktif Rp 2.350.000.', $reply('berapa saldoku'));
        $this->assertSame('Saldo BCA Rp 2.000.000.', $reply('berapa saldo bca?'));
        $this->assertSame('Saldo BCA Rp 2.000.000.', $reply('sebutkan saldo bca'));
        $this->assertSame('Pengeluaran periode ini Rp 750.000.', $reply('berapa pengeluaranku'));
        $this->assertSame('Pengeluaran Makan periode ini Rp 250.000.', $reply('berapa pengeluaran makan'));
        $this->assertSame('Pemasukan periode ini Rp 5.000.000.', $reply('berapa pemasukan'));
        $this->assertSame('Sisa anggaran Makan Rp 750.000.', $reply('sisa anggaran makan'));
        $this->assertSame('Transportasi belum punya anggaran.', $reply('sisa anggaran transportasi'));
        $this->assertSame([], $this->parse('berapa saldo bca')['items']);
    }

    public function test_tidak_menjawab_periode_yang_tidak_diketahui(): void
    {
        foreach (['berapa pengeluaran minggu lalu', 'berapa saldo kemarin', 'berapa pengeluaran hari ini', 'siapa presiden?'] as $text) {
            $result = $this->parse($text);
            $this->assertNull($result['reply'], $text);
            $this->assertStringContainsString('Mode hemat', $result['unclear'], $text);
        }
    }

    public function test_perubahan_akun_tidak_dicatat_sebagai_transaksi(): void
    {
        foreach (['saldo bca sekarang 2 juta', 'uang di dompet tinggal 50 ribu', 'buat akun jago saldo awal 500 ribu', 'ganti nama bca jadi bank', 'hapus transaksi bakso', 'arsipkan gopay'] as $text) {
            $result = $this->parse($text);
            $this->assertSame([], $result['items'], $text);
            $this->assertStringContainsString('Mode hemat', $result['unclear'], $text);
        }
    }

    public function test_ucapan_tanpa_maksud_tidak_jadi_transaksi(): void
    {
        foreach (['halo lax', 'apa kabar', 'ceritakan lelucon'] as $text) {
            $result = $this->parse($text);
            $this->assertSame([], $result['items'], $text);
            $this->assertNotNull($result['unclear'], $text);
        }
    }

    public function test_kata_panggil_dibuang(): void
    {
        $this->assertSame('Bakso', $this->parse('hei lax, bakso 25 ribu')['items'][0]['note']);
    }

    public function test_handle_local_memakai_data_asli_tanpa_memanggil_ai(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $cash = $user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 100000, 'sort' => 0]);
        $food = $user->categories()->create(['name' => 'Konsumsi', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'sort' => 0]);
        $foreign = $other->categories()->create(['name' => 'Asing', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'sort' => 0]);
        $user->transactions()->create(['type' => 'expense', 'amount' => 20000, 'account_id' => $cash->id, 'category_id' => $food->id, 'note' => 'Bakso urat', 'occurred_on' => now()->toDateString()]);
        AssistantExample::create(['user_id' => $other->id, 'text' => 'bakso', 'proposal' => ['items' => []], 'final' => ['items' => [['category_id' => $foreign->id]]], 'applied_at' => now()]);

        $assistant = app(VoiceAssistant::class);

        $result = $assistant->handleLocal($user, 'bakso 25 ribu');
        $this->assertSame(25000, $result['items'][0]['amount']);
        $this->assertSame($cash->id, $result['items'][0]['account_id']); // diisi sanitize()
        $this->assertSame($food->id, $result['items'][0]['category_id']); // belajar dari riwayat sendiri
        $this->assertSame([], $result['items'][0]['issues']);

        $this->assertSame('Total saldo akun aktif Rp 80.000.', $assistant->handleLocal($user, 'berapa saldoku')['reply']);
        $this->assertSame(['Nominal tidak terdengar'], $assistant->handleLocal($user, 'beli 3 kopi')['items'][0]['issues']);

        Http::assertNothingSent();
    }

    public function test_belajar_dari_ucapan_yang_dikonfirmasi(): void
    {
        $user = User::factory()->create();
        $user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);
        $food = $user->categories()->create(['name' => 'Konsumsi', 'type' => 'expense', 'icon' => 'utensils', 'color' => 'clay', 'sort' => 0]);

        AssistantExample::create(['user_id' => $user->id, 'text' => 'jajan seblak', 'proposal' => ['items' => []], 'final' => ['items' => [['category_id' => $food->id]]], 'applied_at' => now()]);
        AssistantExample::create(['user_id' => $user->id, 'text' => 'cilok abang', 'proposal' => ['items' => []]]); // tidak dijalankan

        $this->assertSame([['text' => 'jajan seblak', 'category_id' => $food->id]], LocalParser::training($user));
    }
}
