<?php

namespace App\Enums;

/**
 * صلاحيات عضو الفريق. الأدمن يملكها كلها دائمًا، ويملك وحده الإعدادات وإدارة الفريق.
 *
 * تمرين: أضف صلاحية جديدة هنا (مثلًا حذف إيميل)، ثم افحصها في الـ controller بـ Gate::authorize،
 * وأضف اسمها في lang/ar.json تحت ui.permissions.
 */
enum Permission: string
{
    case ViewEmail = 'email.view';
    case SendEmail = 'email.send';
    case Chat = 'chat.use';

    public function label(): string
    {
        return match ($this) {
            self::ViewEmail => 'قراءة صندوق الإيميل',
            self::SendEmail => 'الرد على الإيميلات وإرسالها',
            self::Chat => 'الدردشة مع المساعد',
        };
    }
}
