<?php

namespace App\Services\Mail;

use App\Models\Setting;
use App\Services\Security\Outbound;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;

/**
 * الإرسال من صندوق الوكيل (الإعدادات ← الإيميل). بيانات الصندوق في قاعدة البيانات لا في .env،
 * لأن الأدمن يغيّرها من الواجهة، فالـ mailer يُبنى وقت الإرسال من الإعدادات الحالية.
 */
class AgentMailer
{
    public const NAME = 'agent';

    /** The mailbox address and its app password are both set. */
    public static function configured(): bool
    {
        return filled(Setting::get('mail.address')) && filled(Setting::get('mail.app_password'));
    }

    /** Point the «agent» mailer at the mailbox in the settings and return its name; null when no mailbox is set. */
    public static function prepare(): ?string
    {
        if (! self::configured()) {
            return null;
        }
        $port = Setting::int('mail.smtp_port');
        // only to a mail server on the server's list, at a public address (SECURITY_AUDIT ٩)
        Outbound::check((string) Setting::get('mail.smtp_host'), $port, 'smtp');
        config(['mail.mailers.'.self::NAME => [
            'transport' => 'smtp',
            'scheme' => $port === 465 ? 'smtps' : 'smtp',
            'host' => Setting::get('mail.smtp_host'),
            'port' => $port,
            'username' => Setting::get('mail.address'),
            'password' => Setting::get('mail.app_password'),
            'timeout' => 30,
        ]]);
        // a mailer built earlier in this process still holds the old settings
        if (($manager = Mail::getFacadeRoot()) instanceof MailManager) {
            $manager->purge(self::NAME);
        }

        return self::NAME;
    }

    /**
     * Route the framework's own mail (password reset, email verification) through the agent mailbox.
     * False when no mailbox is set: the caller then sends nothing, and says so.
     */
    public static function useForSystemMail(): bool
    {
        $name = self::prepare();
        if (! $name) {
            return false;
        }
        config(['mail.default' => $name, 'mail.from' => self::from()]);

        return true;
    }

    /** @return array{address: string, name: string} */
    public static function from(): array
    {
        return ['address' => (string) Setting::get('mail.address'), 'name' => (string) Setting::get('mail.from_name')];
    }
}
