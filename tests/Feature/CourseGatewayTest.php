<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ai\OllamaClient;
use App\Services\Security\Outbound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** مفتاح الدورة: بوابة Ollama الخاصة بالدورة مسموحة من غير ما يعدّل المتدرب .env. */
class CourseGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_course_gateway_is_an_allowed_ai_provider_by_default(): void
    {
        $this->assertNull(Outbound::refusal('api.hr.dev.fyntralink.com', 443, 'ai'));
    }

    public function test_the_assistant_calls_the_gateway_with_the_course_key(): void
    {
        Setting::put('ai.base_url', 'https://api.hr.dev.fyntralink.com');
        Setting::put('ai.api_key', 'hrk_course_key');
        Http::fake(['api.hr.dev.fyntralink.com/api/chat' => Http::response(['message' => ['content' => 'OK'], 'prompt_eval_count' => 5, 'eval_count' => 1])]);

        $r = app(OllamaClient::class)->chat('glm-5.2:cloud', [['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($r->ok());
        Http::assertSent(fn ($req) => $req->url() === 'https://api.hr.dev.fyntralink.com/api/chat'
            && $req->hasHeader('Authorization', 'Bearer hrk_course_key'));
    }
}
