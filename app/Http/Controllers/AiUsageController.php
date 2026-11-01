<?php

namespace App\Http\Controllers;

use App\Models\AiUsage;
use App\Support\VoiceAssistant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Halaman "Pemakaian AI": permintaan & token yang dicatat Sisih sendiri (Google tidak
 * menyediakan API sisa kuota). Hari dihitung per waktu Pasifik karena kuota harian
 * Gemini direset tengah malam Pacific Time.
 */
class AiUsageController extends Controller
{
    private const QUOTA_TZ = 'America/Los_Angeles';

    public function __invoke(Request $request, VoiceAssistant $assistant)
    {
        $appTz = config('app.timezone');
        $now = CarbonImmutable::now(self::QUOTA_TZ);
        $today = $now->toDateString();
        $since = $now->startOfDay()->subDays(29);

        $rows = Schema::hasTable('ai_usages')
            ? AiUsage::where('user_id', $request->user()->id)
                ->where('created_at', '>=', $since->setTimezone($appTz))
                ->orderByDesc('id')
                ->get()
            : collect();
        $day = fn (AiUsage $u) => $u->created_at->copy()->setTimezone(self::QUOTA_TZ)->toDateString();
        // Permintaan yang ditolak karena batas (429) tidak memakai kuota.
        $counted = $rows->where('status', '!=', 'rate_limited');

        // Hari ini, per model (model yang dikonfigurasi selalu tampil walau belum dipakai).
        $configured = $assistant->provider() === 'gemini' || filled(config('services.gemini.key'))
            ? [...config('services.gemini.models'), ...config('services.gemini.tts_models')]
            : [];
        $todayRows = $rows->filter(fn ($u) => $day($u) === $today);
        $models = collect([...$configured, ...$todayRows->pluck('model')])->unique()->values()->map(fn ($model) => [
            'model' => $model,
            'feature' => in_array($model, config('services.gemini.tts_models'), true) ? 'voice' : 'assistant',
            'requests' => $todayRows->where('model', $model)->where('status', '!=', 'rate_limited')->count(),
            'limited' => $todayRows->where('model', $model)->where('status', 'rate_limited')->count(),
            'tokens' => (int) $todayRows->where('model', $model)->sum('total_tokens'),
        ]);

        $days = collect(range(6, 0))->map(function ($ago) use ($now, $counted, $day) {
            $date = $now->subDays($ago)->toDateString();
            $list = $counted->filter(fn ($u) => $day($u) === $date);

            return ['date' => $date, 'requests' => $list->count(), 'tokens' => (int) $list->sum('total_tokens')];
        });

        $features = collect(['assistant', 'voice'])->mapWithKeys(fn ($f) => [$f => [
            'requests' => $counted->where('feature', $f)->count(),
            'tokens' => (int) $counted->where('feature', $f)->sum('total_tokens'),
        ]]);

        return Inertia::render('settings/AiUsage', [
            'provider' => $assistant->providerLabel(),
            'ready' => Schema::hasTable('ai_usages'),
            'today' => [
                'requests' => $counted->filter(fn ($u) => $day($u) === $today)->count(),
                'tokens' => (int) $todayRows->sum('total_tokens'),
                'limited' => $todayRows->where('status', 'rate_limited')->count(),
            ],
            'models' => $models,
            'days' => $days,
            'features' => $features,
            'month' => ['requests' => $counted->count(), 'tokens' => (int) $counted->sum('total_tokens')],
            // Reset kuota harian berikutnya, dalam zona waktu aplikasi (WITA).
            'resetAt' => $now->addDay()->startOfDay()->setTimezone($appTz)->toIso8601String(),
            'recent' => $rows->take(8)->map(fn ($u) => [
                'at' => $u->created_at->toIso8601String(),
                'model' => $u->model,
                'feature' => $u->feature,
                'status' => $u->status,
                'tokens' => $u->total_tokens,
            ])->values(),
        ]);
    }
}
