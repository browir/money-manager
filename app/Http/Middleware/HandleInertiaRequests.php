<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
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

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email']),
            ],
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
            'categories' => fn () => $user
                ? $user->categories()->orderBy('type')->orderBy('sort')->orderBy('id')
                    ->get(['id', 'name', 'type', 'icon', 'color'])
                : [],
        ];
    }
}
