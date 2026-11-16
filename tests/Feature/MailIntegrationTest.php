<?php

namespace Tests\Feature;

use App\Mail\OutgoingEmail;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\ImapClient;
use App\Services\Mail\ImapConnection;
use App\Services\Mail\InboundMessage;
use App\Services\Mail\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A server that answers from a script, and records what the client sent. */
class ScriptedImap implements ImapConnection
{
    public array $sent = [];

    private string $buffer;

    public function __construct(string $script)
    {
        $this->buffer = $script;
    }

    public function line(): string|false
    {
        if ($this->buffer === '') {
            return false;
        }
        $at = strpos($this->buffer, "\n");
        $line = $at === false ? $this->buffer : substr($this->buffer, 0, $at + 1);
        $this->buffer = substr($this->buffer, strlen($line));

        return $line;
    }

    public function read(int $n): string
    {
        $out = substr($this->buffer, 0, $n);
        $this->buffer = substr($this->buffer, $n);

        return $out;
    }

    public function write(string $data): void
    {
        $this->sent[] = $data;
    }

    public function close(): void {}
}

/** The inbox as a list of raw messages by UID. */
class FakeMailbox implements Mailbox
{
    public function __construct(public int $uidvalidity, public array $messages = []) {}

    public function open(): array
    {
        return ['uidvalidity' => $this->uidvalidity, 'uidnext' => ($this->messages ? max(array_keys($this->messages)) : 0) + 1];
    }

    public function after(int $after, int $limit, int $maxBytes): array
    {
        $out = [];
        foreach ($this->messages as $uid => $raw) {
            if ($uid > $after && count($out) < $limit) {
                $out[] = ['uid' => $uid, 'size' => strlen($raw), 'raw' => strlen($raw) > $maxBytes ? null : $raw];
            }
        }

        return $out;
    }

    public function close(): void {}
}

class MailIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function raw(array $o = []): string
    {
        $o += ['from' => 'Sara <sara@example.sa>', 'id' => '<m1@example.sa>', 'headers' => '', 'reply' => '',
            'subject' => '=?UTF-8?B?'.base64_encode('طلب توظيف: محاسب').'?=', 'body' => "السلام عليكم\nمرفق السيرة"];

        return "From: {$o['from']}\r\nTo: careers@x.sa\r\nSubject: {$o['subject']}\r\nMessage-ID: {$o['id']}\r\n{$o['reply']}{$o['headers']}"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($o['body']))
            ."--b1\r\nContent-Type: application/octet-stream; name=\"cv.pdf\"\r\nContent-Disposition: attachment; filename=\"../../cv.pdf\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode("%PDF-1.4\n1 0 obj << >> endobj\n%%EOF"))."--b1--\r\n";
    }

    private function mailbox(): void
    {
        Setting::put('mail.address', 'careers@x.sa');
        Setting::put('mail.app_password', 'app-password');
    }

    public function test_imap_client_speaks_the_protocol(): void
    {
        $msg = "Subject: hi\r\n\r\nbody";
        $conn = new ScriptedImap(
            "* OK ready\r\n"
            ."a1 OK logged in\r\n"
            ."* 3 EXISTS\r\n* OK [UIDVALIDITY 777] ok\r\n* OK [UIDNEXT 43] ok\r\na2 OK [READ-WRITE] done\r\n"
            // «41:*» returns the last message even when it is below 41: the client drops it
            ."* SEARCH 40 41 42\r\na3 OK done\r\n"
            ."* 2 FETCH (UID 41 RFC822.SIZE 120)\r\n* 3 FETCH (UID 42 RFC822.SIZE 99)\r\na4 OK done\r\n"
            .'* 2 FETCH (UID 41 BODY[] {'.strlen($msg)."}\r\n".$msg.")\r\na5 OK done\r\n"
            ."a6 NO [AUTHENTICATIONFAILED] nope\r\n"
        );
        $c = new ImapClient($conn);
        $c->login('careers@x.sa', 'p"w');
        $this->assertSame('a1 LOGIN "careers@x.sa" "p\"w"'."\r\n", $conn->sent[0]);
        $this->assertSame(['uidvalidity' => 777, 'uidnext' => 43], $c->selectInbox());
        $this->assertSame([41, 42], $c->uidsAfter(40));
        $this->assertSame([41 => 120, 42 => 99], $c->sizes([41, 42]));
        $this->assertSame($msg, $c->fetchRaw(41));
        $this->assertStringContainsString('BODY.PEEK[]', $conn->sent[4]);   // never marks the message read

        $this->expectExceptionMessage('NO [AUTHENTICATIONFAILED] nope');
        $c->uidsAfter(0);
    }

    public function test_parses_a_real_email(): void
    {
        $m = InboundMessage::parse($this->raw(['reply' => "In-Reply-To: <out1@x.sa>\r\nReferences: <a@x.sa> <out1@x.sa>\r\n"]));

        $this->assertSame('طلب توظيف: محاسب', $m['subject']);
        $this->assertSame('sara@example.sa', $m['from_email']);
        $this->assertSame('Sara', $m['from_name']);
        $this->assertSame('<m1@example.sa>', $m['message_id']);
        $this->assertSame('<out1@x.sa>', $m['in_reply_to']);
        $this->assertSame(['<a@x.sa>', '<out1@x.sa>'], $m['references']);
        $this->assertStringContainsString('مرفق السيرة', $m['body']);
        $this->assertNull($m['auto']);
        // the name is cleaned of any path, the type read from the bytes, not the label
        $this->assertSame([['cv.pdf', 'application/pdf']], array_map(fn ($a) => [$a['name'], $a['mime']], $m['attachments']));

        $this->assertSame('auto-submitted', InboundMessage::parse($this->raw(['headers' => "Auto-Submitted: auto-replied\r\n"]))['auto']);
        $this->assertSame('system-sender', InboundMessage::parse($this->raw(['from' => 'MAILER-DAEMON@x.sa']))['auto']);
    }

    public function test_an_unsafe_attachment_is_never_stored_and_the_email_says_why(): void
    {
        Storage::fake('local');
        $this->mailbox();
        Setting::put('mail.receiving_enabled', '1');
        Setting::put('mail.imap_uidvalidity', '5');
        Setting::put('mail.imap_last_uid', '0');
        $js = "%PDF-1.7
1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj
%%EOF";
        $raw = "From: Sara <sara@example.sa>
To: careers@x.sa
Subject: cv
Message-ID: <js@x.sa>
MIME-Version: 1.0
"
            ."Content-Type: multipart/mixed; boundary=\"b1\"

--b1
Content-Type: text/plain; charset=UTF-8

hi
"
            ."--b1
Content-Type: application/pdf; name=\"cv.pdf\"
Content-Disposition: attachment; filename=\"cv.pdf\"
Content-Transfer-Encoding: base64

"
            .chunk_split(base64_encode($js))."--b1--
";
        $this->app->instance(Mailbox::class, new FakeMailbox(5, [1 => $raw]));

        $this->artisan('hr:mail-fetch')->assertSuccessful();

        $email = EmailMessage::query()->sole();
        $this->assertSame(0, $email->attachments()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([['name' => 'cv.pdf', 'reason' => 'pdf_active']], $email->refused_attachments);

        $reader = User::factory()->create();
        $reader->forceFill(['permissions' => ['email.view']])->save();
        $refused = $this->actingAs($reader)->get("/mail/{$email->id}")->viewData('page')['props']['email']['refused'];
        $this->assertSame('cv.pdf', $refused[0]['name']);
    }

    public function test_the_login_page_does_not_hand_out_the_admin_routes(): void
    {
        $html = $this->get('/login')->getContent();
        $this->assertStringContainsString('"login":', $html);
        $this->assertStringNotContainsString('"admin.settings":', $html);
        $this->assertStringContainsString('"admin.settings":', $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->getContent());
    }

    public function test_fetch_starts_from_now_then_takes_new_mail_in(): void
    {
        Storage::fake('local');
        $this->mailbox();
        $box = new FakeMailbox(5, [7 => $this->raw(['id' => '<old@x.sa>'])]);
        $this->app->instance(Mailbox::class, $box);

        // off: nothing happens at all
        $this->artisan('hr:mail-fetch')->assertSuccessful();
        $this->assertNull(Setting::get('mail.imap_last_uid'));

        // first run: the archive (uid 7) stays where it is
        Setting::put('mail.receiving_enabled', '1');
        $this->artisan('hr:mail-fetch')->assertSuccessful();
        $this->assertSame('7', Setting::get('mail.imap_last_uid'));
        $this->assertSame(0, EmailMessage::query()->count());

        $box->messages += [
            8 => $this->raw(),
            9 => $this->raw(['id' => '<auto@x.sa>', 'headers' => "Auto-Submitted: auto-replied\r\n"]),
            10 => $this->raw(),                                          // same Message-ID as 8
            11 => $this->raw(['id' => '<self@x.sa>', 'from' => 'careers@x.sa']),
        ];
        $this->artisan('hr:mail-fetch')->assertSuccessful();

        $email = EmailMessage::query()->sole();
        $this->assertSame(['in', 'sara@example.sa', 8, 'طلب توظيف: محاسب'], [$email->direction, $email->from_email, $email->imap_uid, $email->subject]);
        $file = $email->attachments()->sole();
        $this->assertSame(['cv.pdf', 'application/pdf'], [$file->original_name, $file->mime]);
        // stored under a random name, never the sender's «../../cv.pdf»
        $this->assertStringStartsWith('attachments/'.$email->id.'/', $file->path);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame('11', Setting::get('mail.imap_last_uid'));
        $this->assertSame(['imported' => 1, 'skipped' => 3], array_intersect_key(cache('mail.fetch.status'), array_flip(['imported', 'skipped'])));

        // the server renumbered the mailbox: start again from now, import nothing old
        $box->uidvalidity = 6;
        $box->messages[12] = $this->raw(['id' => '<new@x.sa>']);
        $this->artisan('hr:mail-fetch')->assertSuccessful();
        $this->assertSame(1, EmailMessage::query()->count());
        $this->assertSame('12', Setting::get('mail.imap_last_uid'));
    }

    public function test_an_oversized_email_is_skipped(): void
    {
        $this->mailbox();
        Setting::put('mail.receiving_enabled', '1');
        Setting::put('mail.imap_uidvalidity', '5');
        Setting::put('mail.imap_last_uid', '0');
        $this->app->instance(Mailbox::class, new class(5) extends FakeMailbox
        {
            public function after(int $after, int $limit, int $maxBytes): array
            {
                return [['uid' => 1, 'size' => 40 * 1048576, 'raw' => null]];
            }
        });

        $this->artisan('hr:mail-fetch')->assertSuccessful();
        $this->assertSame(0, EmailMessage::query()->count());
        $this->assertSame('1', Setting::get('mail.imap_last_uid'));   // passed over, not retried forever
    }

    private function outgoing(array $attrs = []): EmailMessage
    {
        return EmailMessage::query()->create($attrs + ['direction' => 'out', 'status' => 'queued',
            'to' => ['sara@example.sa'], 'subject' => 'Re: طلب توظيف', 'body_text' => 'نرجو إرسال شهادة المؤهل.',
            'in_reply_to' => '<m1@example.sa>', 'references' => ['<m1@example.sa>']]);
    }

    public function test_send_goes_out_in_the_senders_thread(): void
    {
        Mail::fake();
        $this->mailbox();
        $msg = $this->outgoing();

        // off by default: the queue waits
        $this->artisan('hr:mail-send')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame('queued', $msg->fresh()->status);

        Setting::put('mail.sending_enabled', '1');
        $this->artisan('hr:mail-send')->assertSuccessful();
        $this->artisan('hr:mail-send')->assertSuccessful();   // a second run sends nothing again

        Mail::assertSentCount(1);
        $msg->refresh();
        $this->assertSame('sent', $msg->status);
        $this->assertNotNull($msg->sent_at);
        $this->assertMatchesRegularExpression('/^<[0-9a-f-]+@x\.sa>$/', $msg->message_id);
        Mail::assertSent(OutgoingEmail::class, function (OutgoingEmail $m) use ($msg) {
            $h = $m->headers();

            return $m->hasTo('sara@example.sa') && $h->messageId === trim($msg->message_id, '<>')
                && $h->text['In-Reply-To'] === '<m1@example.sa>' && $h->references === ['<m1@example.sa>'];
        });
    }

    public function test_a_failing_send_is_retried_then_marked_failed_and_can_be_retried(): void
    {
        $this->mailbox();
        Setting::put('mail.sending_enabled', '1');
        // a listed host that can never resolve (.invalid): a real connection failure, not a refused destination
        config(['outbound.hosts' => [...config('outbound.hosts'), 'smtp.nowhere.invalid']]);
        Setting::put('mail.smtp_host', 'smtp.nowhere.invalid');
        Setting::put('mail.smtp_port', '587');
        $msg = $this->outgoing();

        $this->artisan('hr:mail-send');
        $this->assertSame(['queued', 1], [$msg->fresh()->status, $msg->fresh()->send_attempts]);
        $this->artisan('hr:mail-send');                       // too soon: waits for the backoff
        $this->assertSame(1, $msg->fresh()->send_attempts);

        $this->travel(6)->minutes();
        $this->artisan('hr:mail-send');
        $this->travel(11)->minutes();
        $this->artisan('hr:mail-send');

        $msg->refresh();
        $this->assertSame(['failed', 3], [$msg->status, $msg->send_attempts]);
        $this->assertNotNull($msg->error);

        // a person retries it from the email's page
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('mail.retry', $msg))->assertRedirect();
        $this->assertSame(['queued', 0], [$msg->fresh()->status, $msg->fresh()->send_attempts]);
    }

    public function test_an_interrupted_send_is_never_resent_blindly(): void
    {
        Mail::fake();
        $this->mailbox();
        Setting::put('mail.sending_enabled', '1');
        $msg = $this->outgoing(['status' => 'sending']);
        $msg->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();

        $this->artisan('hr:mail-send');
        Mail::assertNothingSent();
        $this->assertSame('failed', $msg->fresh()->status);
    }

    public function test_a_destination_off_the_list_is_never_contacted(): void
    {
        Mail::fake();
        $this->mailbox();
        Setting::put('mail.sending_enabled', '1');
        // the cloud metadata address, and an internal service name: both refused before any connection
        Setting::put('mail.smtp_host', '169.254.169.254');
        $msg = $this->outgoing();

        $this->artisan('hr:mail-send')->assertFailed();
        Mail::assertNothingSent();
        $this->assertSame(['queued', 0], [$msg->fresh()->status, (int) $msg->fresh()->send_attempts]);
    }
}
