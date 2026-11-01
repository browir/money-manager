<?php

namespace App\Support;

use App\Models\AssistantExample;
use App\Models\User;

/**
 * Tahap 3 di docs/rencana-pengurai-lokal.md: seberapa dekat jawaban mesin lokal dengan yang
 * akhirnya disimpan pengguna. Nominal salah dipisah dari nominal kosong, karena yang kosong
 * akan ditanyakan sedangkan yang salah bisa lolos dari tinjauan.
 */
class LocalAccuracy
{
    private const MAX_EXAMPLES = 500;

    /** @return array{examples: int, items: int, amount_right: int, amount_wrong: int, amount_blank: int, type: int, account: int, category: int, date: int, item_count: int} persen, kecuali examples & items */
    public static function measure(User $user): array
    {
        $examples = AssistantExample::where('user_id', $user->id)
            ->whereNotNull('applied_at')
            ->latest('id')->limit(self::MAX_EXAMPLES)
            ->get(['proposal', 'final'])
            ->filter(fn ($e) => isset($e->proposal['local']) && ! empty($e->final['items']));

        $count = ['amount_right' => 0, 'amount_wrong' => 0, 'amount_blank' => 0, 'type' => 0, 'account' => 0, 'category' => 0, 'date' => 0];
        $items = 0;
        $sameCount = 0;

        foreach ($examples as $example) {
            $final = $example->final['items'];
            $local = $example->proposal['local']['items'] ?? [];
            $items += count($final);
            $sameCount += count($final) === count($local) ? 1 : 0;

            foreach (self::pair($final, $local) as [$want, $got]) {
                $amount = (int) ($got['amount'] ?? 0);
                $key = $amount === (int) $want['amount'] ? 'amount_right' : ($amount === 0 ? 'amount_blank' : 'amount_wrong');
                $count[$key]++;

                if ($got !== null) {
                    $count['type'] += $got['type'] === $want['type'] ? 1 : 0;
                    $count['account'] += self::same($got['account_id'], $want['account_id']) && self::same($got['to_account_id'], $want['to_account_id'] ?? null) ? 1 : 0;
                    $count['category'] += self::same($got['category_id'], $want['category_id'] ?? null) ? 1 : 0;
                    $count['date'] += $got['occurred_on'] === $want['occurred_on'] ? 1 : 0;
                }
            }
        }

        $percent = fn (int $n, int $of) => $of > 0 ? (int) round($n / $of * 100) : 0;

        return [
            'examples' => $examples->count(),
            'items' => $items,
            ...array_map(fn ($n) => $percent($n, $items), $count),
            'item_count' => $percent($sameCount, $examples->count()),
        ];
    }

    /**
     * Pasangkan tiap hasil akhir dengan jawaban lokal: nominal sama lebih dulu, karena pengguna
     * bisa membuang atau mengurutkan ulang usulan. Yang tak punya pasangan = terlewat (null).
     *
     * @return list<array{0: array<string, mixed>, 1: ?array<string, mixed>}>
     */
    private static function pair(array $final, array $local): array
    {
        $pairs = [];
        $rest = [];

        foreach ($final as $want) {
            $match = null;
            foreach ($local as $i => $got) {
                if ((int) $got['amount'] > 0 && (int) $got['amount'] === (int) $want['amount']) {
                    $match = $i;
                    break;
                }
            }
            if ($match === null) {
                $rest[] = $want;
            } else {
                $pairs[] = [$want, $local[$match]];
                unset($local[$match]);
            }
        }

        foreach ($rest as $want) {
            $pairs[] = [$want, array_shift($local)];
        }

        return $pairs;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return $a === null || $b === null ? $a === $b : (int) $a === (int) $b;
    }
}
