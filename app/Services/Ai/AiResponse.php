<?php

namespace App\Services\Ai;

/**
 * جواب نموذج واحد. json() يفكّ الجواب ويتحقق من الحقول المطلوبة في الـ Schema،
 * فجواب ناقص أو خارج الشكل يُعامل فشلًا لا بيانات.
 */
final class AiResponse
{
    public function __construct(
        public readonly string $model,
        public readonly string $content,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $durationMs = 0,
        public readonly ?array $schema = null,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $model, string $error, int $ms): self
    {
        return new self(model: $model, content: '', durationMs: $ms, error: $error);
    }

    public function ok(): bool
    {
        return $this->error === null && trim($this->content) !== '';
    }

    /** Decoded JSON when it parses and carries every required field of the schema; null otherwise. */
    public function json(): ?array
    {
        if (! $this->ok()) {
            return null;
        }
        $data = self::decode($this->content);
        if (! is_array($data)) {
            return null;
        }
        foreach ($this->schema['required'] ?? [] as $field) {
            if (! array_key_exists($field, $data)) {
                return null;
            }
        }

        return $data;
    }

    /**
     * Ollama Cloud treats the schema as a hint for some models: glm-5.2 still wraps its
     * JSON in a ```json fence. Accept plain JSON, a fenced block, or the first {...}
     * object in the text; anything else is not JSON.
     */
    public static function decode(string $content): ?array
    {
        $s = trim($content);
        $data = json_decode($s, true);
        if (is_array($data)) {
            return $data;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $s, $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data)) {
                return $data;
            }
        }
        $start = strpos($s, '{');
        $end = strrpos($s, '}');
        if ($start !== false && $end > $start) {
            $data = json_decode(substr($s, $start, $end - $start + 1), true);
        }

        return is_array($data) ? $data : null;
    }
}
