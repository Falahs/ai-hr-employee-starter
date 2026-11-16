<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * لغات الواجهة. ملف واحد لكل لغة (lang/{ar,en}.json) يقرؤه Laravel في __()
 * ويُرسل نفسه للواجهة، فلا تتفرّق النصوص بين مصدرين.
 */
final class Locale
{
    public const SUPPORTED = ['ar', 'en'];

    public const RTL = ['ar'];

    public static function valid(?string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    public static function dir(string $locale): string
    {
        return in_array($locale, self::RTL, true) ? 'rtl' : 'ltr';
    }

    /** @return array<string, string> */
    public static function messages(string $locale): array
    {
        $path = lang_path($locale.'.json');

        return Cache::remember("lang.{$locale}.".(is_file($path) ? filemtime($path) : 0), 3600,
            fn () => is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : []);
    }
}
