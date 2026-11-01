<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Satu panggilan API AI (lihat migrasi create_ai_usages_table). */
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'provider', 'model', 'feature', 'status', 'prompt_tokens', 'output_tokens', 'thought_tokens', 'total_tokens'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Catat tanpa pernah menggagalkan fitur AI (mis. migrasi belum dijalankan di produksi).
     *
     * @param  array{prompt?: int, output?: int, thought?: int, total?: int}  $tokens
     */
    public static function record(string $provider, string $model, string $feature, string $status, array $tokens = []): void
    {
        try {
            $prompt = (int) ($tokens['prompt'] ?? 0);
            $output = (int) ($tokens['output'] ?? 0);
            $thought = (int) ($tokens['thought'] ?? 0);
            static::create([
                'user_id' => auth()->id(),
                'provider' => $provider,
                'model' => $model,
                'feature' => $feature,
                'status' => $status,
                'prompt_tokens' => $prompt,
                'output_tokens' => $output,
                'thought_tokens' => $thought,
                'total_tokens' => (int) ($tokens['total'] ?? $prompt + $output + $thought),
            ]);
        } catch (Throwable $e) {
            Log::warning('Gagal mencatat pemakaian AI', ['message' => $e->getMessage()]);
        }
    }

    /** Token dari usageMetadata Gemini. */
    public static function geminiTokens(?array $meta): array
    {
        return [
            'prompt' => $meta['promptTokenCount'] ?? 0,
            'output' => $meta['candidatesTokenCount'] ?? 0,
            'thought' => $meta['thoughtsTokenCount'] ?? 0,
            'total' => $meta['totalTokenCount'] ?? null,
        ];
    }
}
