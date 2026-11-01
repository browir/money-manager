<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Satu contoh latih pengurai lokal LAX (lihat docs/rencana-pengurai-lokal.md).
 * Jawaban AI (reply) tidak disimpan karena bisa memuat saldo; cukup penanda "answered".
 */
class AssistantExample extends Model
{
    public const KEEP_DAYS = 730;

    private const ITEM_FIELDS = ['type', 'amount', 'account_id', 'to_account_id', 'category_id', 'note', 'occurred_on'];

    private const ACTION_FIELDS = ['action', 'account_id', 'name', 'type', 'amount', 'note'];

    protected $fillable = ['user_id', 'text', 'model', 'default_account_id', 'proposal', 'final', 'applied_at'];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'final' => 'array', 'applied_at' => 'datetime'];
    }

    /**
     * Simpan hasil urai tanpa pernah menggagalkan asisten (mis. migrasi belum dijalankan di produksi).
     * Tidak ada cron di Vercel, jadi contoh lama dibuang di sini.
     *
     * @param  array<string, mixed>  $result  keluaran VoiceAssistant::handle()
     */
    public static function capture(User $user, string $text, ?int $defaultAccountId, ?string $model, array $result): ?int
    {
        try {
            static::where('user_id', $user->id)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

            return static::create([
                'user_id' => $user->id,
                'text' => $text,
                'model' => $model,
                'default_account_id' => $defaultAccountId,
                'proposal' => [
                    ...self::fields($result['items'] ?? [], $result['account_actions'] ?? []),
                    'answered' => filled($result['reply'] ?? null),
                    'unclear' => $result['unclear'] ?? null,
                ],
            ])->id;
        } catch (Throwable $e) {
            self::warn($e);

            return null;
        }
    }

    /** Catat apa yang akhirnya disimpan pengguna; contoh milik orang lain diabaikan. */
    public static function finish(User $user, int $id, array $items, array $accountActions): void
    {
        try {
            static::where('user_id', $user->id)->whereKey($id)->update([
                'final' => json_encode(self::fields($items, $accountActions)),
                'applied_at' => now(),
            ]);
        } catch (Throwable $e) {
            self::warn($e);
        }
    }

    /** @return array{items: list<array<string, mixed>>, account_actions: list<array<string, mixed>>} */
    private static function fields(array $items, array $accountActions): array
    {
        return [
            'items' => array_values(array_map(fn ($i) => Arr::only($i, self::ITEM_FIELDS), $items)),
            'account_actions' => array_values(array_map(fn ($a) => Arr::only($a, self::ACTION_FIELDS), $accountActions)),
        ];
    }

    /** Hanya kode galat: pesan QueryException memuat isi query, termasuk ucapan & nominal pengguna. */
    private static function warn(Throwable $e): void
    {
        Log::warning('Gagal menyimpan contoh asisten', ['exception' => $e::class, 'code' => $e->getCode()]);
    }
}
