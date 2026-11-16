<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Services\Documents\UploadGuard;
use App\Services\Mail\AgentMailer;
use App\Services\Mail\InboundMessage;
use App\Services\Mail\Mailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * يقرأ الإيميلات الجديدة من صندوق الوكيل ويحفظها في email_messages، ومرفقاتها في email_attachments.
 *
 * - يتتبع الرسائل برقمها (UID) لا بعلامة «مقروء»، فلو فتح أحد الإيميل في Gmail ما يختفي عن النظام.
 * - أول تشغيل يسجّل نقطة البداية فقط: ما يسحب أرشيف الصندوق القديم، يبدأ من اللي يوصل بعد التفعيل.
 * - المؤشر يتقدم بعد كل رسالة، فلو انقطع التشغيل في النص يعيد رسالة واحدة على الأكثر، والمكرر
 *   يُكشف برقم Message-ID.
 * - الردود الآلية والارتدادات ما تنحفظ.
 *
 * تمرين: هنا المكان اللي تبدأ منه أتمتتك. بعد ما ينحفظ الإيميل، ارسله لوكيل يصنّفه
 * (طلب توظيف؟ استفسار؟) أو يستخرج منه بيانات المتقدم.
 */
class FetchMail extends Command
{
    protected $signature = 'hr:mail-fetch {--limit=25 : messages per run}';

    protected $description = 'Read new mail from the agent mailbox into the inbox';

    /** Gmail's own limit is 25 MB per message. */
    public const MAX_MESSAGE_BYTES = 30 * 1024 * 1024;

    public function handle(Mailbox $box): int
    {
        if (! Setting::bool('mail.receiving_enabled') || ! AgentMailer::configured()) {
            return self::SUCCESS;
        }

        $status = ['at' => now()->toIso8601String(), 'ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => null, 'baseline' => false];
        try {
            $info = $box->open();
            $validity = Setting::get('mail.imap_uidvalidity');

            if ($validity === null || (int) $validity !== $info['uidvalidity']) {
                // first run, or the server renumbered the mailbox: start from what arrives next
                Setting::put('mail.imap_uidvalidity', (string) $info['uidvalidity']);
                Setting::put('mail.imap_last_uid', (string) max(0, $info['uidnext'] - 1));
                AuditLog::record('mail.fetch_started', null, ['uidvalidity' => $info['uidvalidity'], 'from_uid' => $info['uidnext']]);
                $status = ['ok' => true, 'baseline' => true] + $status;
                $this->info('baseline set: mail from now on will be read');

                return self::SUCCESS;
            }

            foreach ($box->after((int) Setting::get('mail.imap_last_uid'), (int) $this->option('limit'), self::MAX_MESSAGE_BYTES) as $m) {
                $this->importOne($m) ? $status['imported']++ : $status['skipped']++;
                Setting::put('mail.imap_last_uid', (string) $m['uid']);
            }
            $status['ok'] = true;
            $this->info("imported {$status['imported']}, skipped {$status['skipped']}");
        } catch (\Throwable $e) {
            // the server's message only; the password never appears in it
            $status['error'] = mb_substr($e->getMessage(), 0, 200);
            report($e);
            $this->error($status['error']);
        } finally {
            $box->close();
            Cache::forever('mail.fetch.status', $status);
        }

        return $status['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array{uid: int, size: int, raw: ?string} $m */
    private function importOne(array $m): bool
    {
        if ($m['raw'] === null) {
            return false; // bigger than MAX_MESSAGE_BYTES: left in the mailbox, never downloaded
        }

        $mail = InboundMessage::parse($m['raw']);
        $own = strtolower((string) Setting::get('mail.address'));

        if ($mail['auto'] || ! $mail['from_email'] || $mail['from_email'] === $own
            || ($mail['message_id'] && EmailMessage::query()->where('message_id', $mail['message_id'])->exists())) {
            return false;
        }

        $email = EmailMessage::query()->create([
            'direction' => 'in', 'status' => 'received',
            'message_id' => $mail['message_id'], 'in_reply_to' => $mail['in_reply_to'], 'references' => $mail['references'],
            'from_email' => $mail['from_email'], 'from_name' => $mail['from_name'], 'to' => $mail['to'],
            'subject' => $mail['subject'], 'body_text' => $mail['body'], 'imap_uid' => $m['uid'],
        ]);

        $guard = new UploadGuard;
        $refused = [];
        foreach ($mail['attachments'] as $a) {
            // checked before it is stored: the real type from the bytes, and what is inside (UploadGuard)
            $tmp = tempnam(sys_get_temp_dir(), 'att');
            file_put_contents($tmp, $a['content']);
            try {
                $check = $guard->inspect($tmp);
                if ($check['reason'] !== null) {
                    // never stored, so nobody can open it; the email says what was refused and why
                    $refused[] = ['name' => UploadGuard::cleanName($a['name'], $check['mime'] ?? 'application/octet-stream'), 'reason' => $check['reason']];

                    continue;
                }
                if (in_array($check['mime'], UploadGuard::IMAGES, true)) {
                    $guard->reencodeImage($tmp, $check['mime']);   // whatever rode along in the picture does not survive
                }
                // a random name on disk: the sender's file name is shown, never used as a path
                $path = 'attachments/'.$email->id.'/'.Str::random(32);
                Storage::disk('local')->put($path, (string) file_get_contents($tmp));
                $email->attachments()->create(['path' => $path, 'original_name' => UploadGuard::cleanName($a['name'], $check['mime']),
                    'mime' => $check['mime'], 'size' => (int) filesize($tmp)]);
            } finally {
                @unlink($tmp);
            }
        }
        if ($refused) {
            $email->forceFill(['refused_attachments' => $refused])->save();
        }

        return true;
    }
}
