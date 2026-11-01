<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Pencatatan pemakaian AI & halaman "Pemakaian AI". */
class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_panggilan_gemini_dicatat_beserta_token_dan_batas(): void
    {
        $user = User::factory()->create();
        $user->accounts()->create(['name' => 'Tunai', 'type' => 'cash', 'color' => 'moss', 'initial_balance' => 0, 'sort' => 0]);
        config(['services.gemini.key' => 'kunci-uji', 'services.gemini.models' => ['model-a', 'model-b'], 'services.gemini.tts_models' => ['tts-a']]);
        Http::fake([
            '*/models/model-a:generateContent' => Http::response([], 429),
            '*/models/model-b:generateContent' => Http::response([
                'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"reply":"Halo","items":[],"account_actions":[],"encouragement":null,"unclear":null}']]]]],
                'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 60, 'thoughtsTokenCount' => 40, 'totalTokenCount' => 1000],
            ]),
            '*/models/tts-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode("\0\0")]]]]]],
                'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 80, 'totalTokenCount' => 100],
            ]),
        ]);

        $this->actingAs($user)->postJson(route('assistant.parse'), ['text' => 'saldo berapa'])->assertOk();
        $this->post(route('assistant.speak'), ['text' => 'Baik, Tuan.'])->assertOk();

        $this->assertSame(['rate_limited', 'ok', 'ok'], AiUsage::orderBy('id')->pluck('status')->all());
        $this->assertSame(1000, AiUsage::where('model', 'model-b')->value('total_tokens'));
        $this->assertSame('voice', AiUsage::where('model', 'tts-a')->value('feature'));
        $this->assertSame($user->id, AiUsage::first()->user_id);

        $this->get(route('settings.ai'))->assertInertia(fn (Assert $page) => $page
            ->component('settings/AiUsage')
            ->where('today.requests', 2) // 429 tidak memakai kuota
            ->where('today.tokens', 1100)
            ->where('today.limited', 1)
            ->where('features.assistant.requests', 1)
            ->where('features.voice.tokens', 100)
            ->where('models.0.model', 'model-a')
            ->where('models.0.limited', 1)
            ->has('days', 7)
        );
    }

    public function test_pemakaian_hanya_milik_sendiri(): void
    {
        $other = User::factory()->create();
        AiUsage::create(['user_id' => $other->id, 'provider' => 'gemini', 'model' => 'x', 'feature' => 'assistant', 'status' => 'ok', 'total_tokens' => 500]);

        $this->actingAs(User::factory()->create())->get(route('settings.ai'))
            ->assertInertia(fn (Assert $page) => $page->where('month.requests', 0)->where('month.tokens', 0));
    }
}
