<?php

namespace App\Services\Ai;

use App\Models\Setting;
use App\Services\Security\Outbound;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * الاتصال بـ Ollama (/api/chat). كل طلب للذكاء الاصطناعي يمرّ من هنا، فالمزوّد يتبدّل
 * لاحقًا بتبديل هذا الصنف وحده.
 *
 * - format: JSON Schema اختياري يقيّد شكل الجواب (تحتاجه لما تبني وكيلًا يرجّع بيانات).
 * - max_tokens واسع افتراضيًا: النماذج اللي تفكّر قبل الجواب ترجع جوابًا فارغًا لو الحدّ ضيّق.
 * - العنوان لازم يكون في قائمة الخوادم المسموحة (config/outbound.php)، على عنوان عام.
 *
 * تمرين: سجّل كل طلب في جدول (النموذج، التوكنز، المدة، النجاح) عشان تعرف كم يكلّفك.
 */
class OllamaClient
{
    private ?string $baseUrl = null;

    private ?string $apiKey = null;

    /** A copy that talks to another endpoint/key, for «جرّب الاتصال» before the values are saved. */
    public function withOverrides(string $baseUrl, string $apiKey): self
    {
        $copy = clone $this;
        $copy->baseUrl = $baseUrl;
        $copy->apiKey = $apiKey;

        return $copy;
    }

    /** @param list<array{role: string, content: string}> $messages */
    public function chat(string $model, array $messages, ?array $schema = null): AiResponse
    {
        $started = hrtime(true);
        $base = rtrim($this->baseUrl ?? (string) Setting::get('ai.base_url'), '/');
        [$host, $port] = Outbound::fromUrl($base);
        if ($why = Outbound::refusal($host, $port, 'ai')) {
            return AiResponse::failed($model, "outbound refused: {$host}:{$port} ({$why})", $this->ms($started));
        }
        try {
            $res = Http::baseUrl($base)
                ->withToken($this->apiKey ?? (string) Setting::get('ai.api_key'))
                ->timeout(Setting::int('ai.timeout_seconds'))
                ->acceptJson()
                ->post('/api/chat', array_filter([
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                    'format' => $schema,
                    'options' => ['num_predict' => Setting::int('ai.max_tokens'), 'temperature' => 0.2],
                ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            return AiResponse::failed($model, 'connection: '.$e->getMessage(), $this->ms($started));
        }

        if ($res->failed()) {
            return AiResponse::failed($model, 'http '.$res->status().': '.mb_substr($res->body(), 0, 300), $this->ms($started));
        }

        return new AiResponse(
            model: $model,
            content: (string) $res->json('message.content', ''),
            inputTokens: (int) $res->json('prompt_eval_count', 0),
            outputTokens: (int) $res->json('eval_count', 0),
            durationMs: $this->ms($started),
            schema: $schema,
        );
    }

    private function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
