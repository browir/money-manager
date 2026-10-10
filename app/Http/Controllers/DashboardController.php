<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        // Periode mengikuti tanggal gajian pengguna (?month=2026-10 = periode yang dimulai Okt 2026).
        $period = $user->period($request->query('month'));
        [$start, $end] = $period->range();
        [$prevStart, $prevEnd] = $period->previous()->range();

        $totals = fn (string $from, string $to) => $user->transactions()
            ->whereBetween('occurred_on', [$from, $to])
            ->whereIn('type', ['income', 'expense'])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense")
            ->toBase()
            ->first();

        $current = $totals($start, $end);
        $previous = $totals($prevStart, $prevEnd);

        $byCategory = $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('occurred_on', [$start, $end])
            ->groupBy('category_id')
            ->select('category_id', DB::raw('SUM(amount) AS total'), DB::raw('COUNT(*) AS count'))
            ->orderByDesc('total')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'total' => (int) $row->total,
                'count' => (int) $row->count,
            ]);

        $daily = $user->transactions()
            ->whereIn('type', ['income', 'expense'])
            ->whereBetween('occurred_on', [$start, $end])
            ->groupBy('occurred_on', 'type')
            ->select('occurred_on', 'type', DB::raw('SUM(amount) AS total'))
            ->toBase()
            ->get();

        $days = [];
        foreach ($period->days() as $d) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'day' => $d->day, 'income' => 0, 'expense' => 0];
        }
        foreach ($daily as $row) {
            $days[substr((string) $row->occurred_on, 0, 10)][$row->type] = (int) $row->total;
        }

        $recent = $user->transactions()
            ->whereBetween('occurred_on', [$start, $end])
            ->latest('occurred_on')
            ->latest('id')
            ->limit(6)
            ->get()
            ->map->toListItem();

        // Jadwal berulang yang sudah jatuh tempo, menunggu dikonfirmasi.
        $due = $user->recurrings()
            ->whereDate('next_due', '<=', now()->toDateString())
            ->orderBy('next_due')
            ->get()
            ->map->toListItem();

        return Inertia::render('Dashboard', [
            'due' => $due,
            'month' => $period->key(),
            'totals' => [
                'income' => (int) $current->income,
                'expense' => (int) $current->expense,
                'prevIncome' => (int) $previous->income,
                'prevExpense' => (int) $previous->expense,
            ],
            'byCategory' => $byCategory,
            'daily' => array_values($days),
            'recent' => $recent,
        ]);
    }
}
