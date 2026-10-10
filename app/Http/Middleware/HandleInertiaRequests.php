<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Akun dan kategori dibagikan ke semua halaman karena form transaksi
     * cepat bisa dibuka dari mana saja. Datanya kecil untuk satu pengguna.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $period = $user?->period();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email']),
            ],
            // Awal periode bulanan (tanggal gajian) dan rentang periode berjalan.
            'period' => $period ? [
                'startDay' => $period->startDay,
                'current' => $period->key(),
                'start' => $period->start->toDateString(),
                'end' => $period->end->toDateString(),
            ] : null,
            'accounts' => fn () => $user
                ? $user->accounts()->withBalance()->orderBy('sort')->orderBy('id')->get()
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'type' => $a->type,
                        'color' => $a->color,
                        'initial_balance' => $a->initial_balance,
                        'balance' => $a->balance,
                        'archived' => $a->archived_at !== null,
                    ])
                : [],
            // uses  = jumlah pemakaian 90 hari terakhir (urutan pilihan di form cepat).
            // spent = pengeluaran periode berjalan (sisa anggaran).
            // urgent = kategori darurat, pengeluarannya di luar anggaran bulanan.
            'categories' => fn () => $user
                ? $user->categories()
                    ->select(['id', 'name', 'type', 'icon', 'color', 'budget', 'urgent'])
                    ->withCount(['transactions as uses' => fn ($q) => $q->where('occurred_on', '>=', now()->subDays(90)->toDateString())])
                    ->withSum(['transactions as spent' => fn ($q) => $q->where('type', 'expense')
                        ->whereBetween('occurred_on', $period->range())], 'amount')
                    ->orderBy('type')->orderBy('sort')->orderBy('id')
                    ->get()
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'type' => $c->type,
                        'icon' => $c->icon,
                        'color' => $c->color,
                        'budget' => $c->budget,
                        'urgent' => (bool) $c->urgent,
                        'uses' => (int) $c->uses,
                        'spent' => (int) $c->spent,
                    ])
                : [],
            // Catatan yang sering ditulis, untuk saran isian di form cepat.
            'noteSuggestions' => fn () => $user
                ? $user->transactions()
                    ->whereIn('type', ['income', 'expense'])
                    ->whereNotNull('note')->where('note', '!=', '')
                    ->where('occurred_on', '>=', now()->subDays(180)->toDateString())
                    ->groupBy('note', 'category_id', 'type')
                    ->select('note', 'category_id', 'type', DB::raw('COUNT(*) AS uses'))
                    ->orderByDesc('uses')
                    ->limit(80)
                    ->toBase()
                    ->get()
                    ->map(fn ($row) => [
                        'note' => $row->note,
                        'category_id' => $row->category_id,
                        'type' => $row->type,
                    ])
                : [],
        ];
    }
}
