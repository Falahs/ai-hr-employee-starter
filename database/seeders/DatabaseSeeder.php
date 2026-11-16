<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * حسابات الديمو وإيميلات تجريبية، عشان النظام يشتغل من أول تشغيل قبل ربط أي صندوق.
 *
 * - demo@example.com: أدمن، يشوف كل شيء (الإعدادات والفريق).
 * - demo-member@example.com: عضو بصلاحيات محدودة (يقرأ الإيميل ويدردش، ما يرسل)، عشان تشوف الفرق.
 *
 * كلمة المرور من DEMO_PASSWORD، وإذا ما انحطت فهي القيمة المكتوبة في README.md.
 * كل الأسماء في الإيميلات وهمية. التشغيل يتكرر بأمان: ينشئ الناقص فقط.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_ADMIN = 'demo@example.com';

    public const DEMO_MEMBER = 'demo-member@example.com';

    public const DEFAULT_PASSWORD = 'Demo-Starter-2026';

    public function run(): void
    {
        $password = (string) (env('DEMO_PASSWORD') ?: self::DEFAULT_PASSWORD);

        $this->account(self::DEMO_ADMIN, 'Demo Admin · مدير تجريبي', $password, admin: true, permissions: []);
        $this->account(self::DEMO_MEMBER, 'Demo Member · عضو تجريبي', $password, admin: false,
            permissions: [Permission::ViewEmail->value, Permission::Chat->value]);

        if (! EmailMessage::query()->exists()) {
            $this->sampleEmails();
        }
    }

    /** Creates the account once; afterwards only its password follows DEMO_PASSWORD, nothing else is touched. */
    private function account(string $email, string $name, string $password, bool $admin, array $permissions): void
    {
        $user = User::query()->where('email', $email)->first();
        if ($user) {
            if (! Hash::check($password, $user->password)) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            return;
        }

        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        $user->forceFill(['email_verified_at' => now(), 'is_admin' => $admin, 'permissions' => $permissions])->save();
    }

    private function sampleEmails(): void
    {
        $samples = [
            ['لينا حداد', 'lina.haddad@example.com', 'طلب توظيف: محاسبة', 3, <<<'TXT'
                السلام عليكم،

                أرغب في التقديم على وظيفة محاسبة لديكم. خبرتي أربع سنوات في شركة نورث ويند للتجارة،
                أعمل على إعداد القوائم المالية الشهرية والتسويات البنكية، وأستخدم برنامج SAP.
                حاصلة على بكالوريوس محاسبة وشهادة SOCPA قيد الإكمال.

                أقدر أرسل السيرة الذاتية متى طلبتوها. شاكرة لكم.
                لينا حداد
                TXT],
            ['سامي يوسف', 'sami.yousef@example.com', 'استفسار عن وظيفة مطوّر ويب', 2, <<<'TXT'
                مرحبًا،

                شفت إعلانكم عن وظيفة مطوّر ويب. عندي خبرة سنتين في Laravel وVue،
                وأبغى أعرف: هل الوظيفة عن بعد أو من المكتب؟ وهل فيه فترة تجربة؟

                تحياتي،
                سامي يوسف
                TXT],
            ['Maya Collins', 'maya.collins@example.com', 'Application: HR Coordinator', 1, <<<'TXT'
                Hello,

                I would like to apply for the HR Coordinator role. I have three years of experience
                handling onboarding and interview scheduling at Contoso Retail, and I speak Arabic and English.

                I can start within one month and can send my CV on request.

                Best regards,
                Maya Collins
                TXT],
        ];

        foreach ($samples as [$name, $email, $subject, $daysAgo, $body]) {
            $m = EmailMessage::query()->create([
                'direction' => 'in', 'status' => 'received', 'from_email' => $email, 'from_name' => $name,
                'to' => ['hr@example.com'], 'subject' => $subject, 'body_text' => $body,
                'message_id' => '<demo-'.md5($email).'@example.com>',
            ]);
            $m->forceFill(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)])->save();
        }
    }
}
