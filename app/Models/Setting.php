<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * إعدادات النظام من لوحة الأدمن: مفتاح/قيمة، والسرّي منها (مفتاح النموذج،
 * كلمة مرور تطبيق الإيميل) يُحفظ مشفّرًا ولا يُعاد للواجهة كاملًا أبدًا.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'encrypted', 'updated_by'];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean'];
    }

    /** Defaults live here, so a fresh install works and «استرجاع الافتراضي» has something to return to. */
    public const DEFAULTS = [
        'ai.base_url' => 'https://ollama.com',
        'ai.model' => 'glm-5.2:cloud',
        'ai.max_tokens' => '8192',          // a model that thinks first returns an empty answer under a tight cap
        'ai.timeout_seconds' => '120',
        'ai.instructions' => "أنت مساعد فريق التوظيف. أجب بالعربية باختصار ووضوح.
اعتمد فقط على الإيميلات المرفقة في السياق، ولا تخترع معلومة غير موجودة فيها.
إذا ما لقيت الجواب في الإيميلات قل ذلك صراحة.",
        'mail.imap_host' => 'imap.gmail.com',
        'mail.imap_port' => '993',
        'mail.smtp_host' => 'smtp.gmail.com',
        'mail.smtp_port' => '465',
        'mail.address' => '',
        'mail.from_name' => 'فريق التوظيف',
        // the mailbox does nothing until the admin turns each direction on
        'mail.sending_enabled' => '0',
        'mail.receiving_enabled' => '0',
    ];

    public const SECRETS = ['ai.api_key', 'mail.app_password'];

    public static function get(string $key, ?string $default = null): ?string
    {
        // The cache holds the stored (still encrypted) values; secrets are decrypted per read, never cached in clear.
        $all = Cache::rememberForever('settings.all', fn () => static::query()->get(['key', 'value', 'encrypted'])
            ->mapWithKeys(fn (self $s) => [$s->key => [$s->value, $s->encrypted]])->all());

        if (isset($all[$key]) && $all[$key][0] !== null) {
            [$value, $encrypted] = $all[$key];

            return $encrypted ? Crypt::decryptString($value) : $value;
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function int(string $key): int
    {
        return (int) static::get($key);
    }

    public static function bool(string $key): bool
    {
        return static::get($key) === '1';
    }

    public static function put(string $key, ?string $value, ?User $by = null): void
    {
        $secret = in_array($key, self::SECRETS, true);
        static::query()->updateOrCreate(['key' => $key], [
            'value' => $secret && $value !== null ? Crypt::encryptString($value) : $value,
            'encrypted' => $secret,
            'updated_by' => $by?->id,
        ]);
        Cache::forget('settings.all');
    }

    /** For the settings screen: secrets show only that they are set, plus the last 4 characters. */
    public static function masked(string $key): ?string
    {
        $v = static::get($key);

        return $v ? '••••'.mb_substr($v, -4) : null;
    }
}
