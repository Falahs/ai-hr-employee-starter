<?php

namespace App\Services\Ai;

/**
 * نص الإيميلات يوصل للنموذج داخل وسم (<emails>)، والتعليمات تقول إن اللي داخله بيانات. هذا يصمد بس
 * إذا ما قدر شيء داخل النص يقفل الوسم: إيميل فيه «</emails>» وبعدها «تجاهل التعليمات» كان بيقفل البيانات
 * ويبدأ «تعليمات». فأي فتح أو إغلاق لوسومنا داخل نص المرسل يتعطّل قبل ما ينحط في الوسم.
 *
 * Every opening or closing of one of our tags inside sender text is defused before it is wrapped.
 */
final class Fence
{
    public const TAGS = ['emails', 'email', 'document', 'data'];

    public static function data(string $text): string
    {
        return (string) preg_replace('#<\s*(/?)\s*('.implode('|', self::TAGS).')\b#iu', '‹$1$2', $text);
    }
}
