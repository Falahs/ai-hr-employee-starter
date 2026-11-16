<?php

namespace App\Console\Commands;

use App\Mail\OutgoingEmail;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Services\Mail\AgentMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * يرسل الإيميلات الواقفة «في طابور الإرسال» من صندوق الوكيل.
 *
 * - كل إيميل يُحجز قبل إرساله (queued ← sending بتحديث مشروط)، فتشغيلان متزامنان ما يرسلان نفس الإيميل.
 * - الفشل يُعاد بعد انتظار يزيد مع كل محاولة، وبعد ثلاث يصير «فشل الإرسال» ويظهر سببه في الصندوق.
 * - إيميل علق في «sending» (انقطع التشغيل وقت الإرسال) ما يُعاد تلقائيًا: ممكن يكون وصل فعلًا،
 *   فيصير «فشل»، والشخص يتأكد من مجلد المرسل قبل ما يعيده.
 */
class SendMail extends Command
{
    protected $signature = 'hr:mail-send {--limit=30 : messages per run}';

    protected $description = 'Send the queued outgoing emails from the agent mailbox';

    public const MAX_ATTEMPTS = 3;

    /** Minutes to wait before attempt n+1: 5, then 10. */
    public const BACKOFF_MINUTES = 5;

    public function handle(): int
    {
        if (! Setting::bool('mail.sending_enabled')) {
            return self::SUCCESS;
        }
        try {
            $mailer = AgentMailer::prepare();
        } catch (\RuntimeException $e) {
            // a mail server off the allowed list: nothing is sent and the queue is left as it is
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $mailer) {
            return self::SUCCESS;
        }

        EmailMessage::query()->where('direction', 'out')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(15))
            ->get()->each(fn (EmailMessage $m) => $this->giveUp($m, 'interrupted while sending; check the Sent folder before retrying'));

        $due = EmailMessage::query()->where('direction', 'out')->where('status', 'queued')->orderBy('id')->limit(200)->get()
            ->filter(fn (EmailMessage $m) => (int) $m->send_attempts === 0 || $m->updated_at <= now()->subMinutes(self::BACKOFF_MINUTES * (int) $m->send_attempts))
            ->take((int) $this->option('limit'));

        $sent = 0;
        foreach ($due as $m) {
            // claim it: only one run moves it out of «queued»
            if (! EmailMessage::query()->whereKey($m->id)->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()])) {
                continue;
            }
            $m->refresh();
            if (! array_filter($m->to ?? [])) {
                $this->giveUp($m, 'no recipient address');

                continue;
            }

            $from = AgentMailer::from();
            $id = $m->message_id ?: '<'.Str::uuid().'@'.(Str::after($from['address'], '@') ?: 'agent.local').'>';
            try {
                Mail::mailer($mailer)->send(new OutgoingEmail($m, $id));
                $m->forceFill(['status' => 'sent', 'sent_at' => now(), 'message_id' => $id, 'error' => null,
                    'from_email' => $from['address'], 'from_name' => $from['name'], 'send_attempts' => (int) $m->send_attempts + 1])->save();
                $sent++;
            } catch (\Throwable $e) {
                $attempts = (int) $m->send_attempts + 1;
                $error = mb_substr($e->getMessage(), 0, 300);
                report($e);
                $attempts >= self::MAX_ATTEMPTS
                    ? $this->giveUp($m->forceFill(['send_attempts' => $attempts]), $error)
                    : $m->forceFill(['status' => 'queued', 'send_attempts' => $attempts, 'error' => $error])->save();
            }
        }

        if ($sent || $due->isNotEmpty()) {
            $this->info("sent {$sent} of {$due->count()}");
        }

        return self::SUCCESS;
    }

    /** Stop trying; the inbox shows it as failed, with the server's reason. */
    private function giveUp(EmailMessage $m, string $error): void
    {
        $m->forceFill(['status' => 'failed', 'error' => $error])->save();
    }
}
