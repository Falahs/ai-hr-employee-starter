<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $permissions): User
    {
        $u = User::factory()->create();
        $u->forceFill(['permissions' => $permissions])->save();

        return $u;
    }

    private function incoming(array $attrs = []): EmailMessage
    {
        return EmailMessage::query()->create($attrs + ['direction' => 'in', 'status' => 'received', 'from_email' => 'lina@example.com',
            'from_name' => 'لينا حداد', 'subject' => 'طلب توظيف', 'body_text' => 'السلام عليكم', 'message_id' => '<in-1@example.com>',
            'references' => ['<earlier@example.com>']]);
    }

    public function test_the_mailbox_needs_its_permission(): void
    {
        $email = $this->incoming();
        $none = $this->member([]);

        $this->get('/mail')->assertRedirect('/login');
        $this->actingAs($none)->get('/mail')->assertForbidden();
        $this->actingAs($none)->get("/mail/{$email->id}")->assertForbidden();
        $this->actingAs($none)->post('/mail', ['to' => 'x@example.com', 'subject' => 's', 'body' => 'b'])->assertForbidden();
        $this->assertSame(1, EmailMessage::query()->count());
    }

    public function test_a_reader_sees_the_inbox_and_opening_marks_read_but_cannot_send(): void
    {
        $email = $this->incoming();
        $reader = $this->member(['email.view']);

        $page = $this->actingAs($reader)->get('/mail')->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $page['unread']);
        $this->assertSame('طلب توظيف', $page['emails']['data'][0]['subject']);

        $show = $this->actingAs($reader)->get("/mail/{$email->id}")->assertOk()->viewData('page')['props'];
        $this->assertFalse($show['can_send']);
        $this->assertNotNull($email->fresh()->read_at);

        $this->actingAs($reader)->post('/mail', ['reply_to' => $email->id, 'body' => 'شكرًا'])->assertForbidden();
    }

    public function test_search_finds_by_subject_or_sender(): void
    {
        $this->incoming();
        $this->incoming(['message_id' => '<in-2@example.com>', 'from_email' => 'sami@example.com', 'from_name' => 'Sami', 'subject' => 'Question']);
        $reader = $this->member(['email.view']);

        $subjects = fn (string $q) => array_column($this->actingAs($reader)->get('/mail?q='.urlencode($q))->viewData('page')['props']['emails']['data'], 'subject');
        $this->assertSame(['Question'], $subjects('SAMI'));
        $this->assertSame(['طلب توظيف'], $subjects('توظيف'));
        $this->assertSame([], $subjects('100%'));   // a % in the search is a character, not a wildcard
    }

    public function test_a_reply_is_queued_in_the_senders_thread(): void
    {
        $email = $this->incoming();
        $sender = $this->member(['email.view', 'email.send']);

        $this->actingAs($sender)->post('/mail', ['reply_to' => $email->id, 'body' => 'وصلنا طلبك، شكرًا.'])->assertRedirect();

        $out = EmailMessage::query()->where('direction', 'out')->sole();
        $this->assertSame(['queued', ['lina@example.com'], 'Re: طلب توظيف', '<in-1@example.com>'], [$out->status, $out->to, $out->subject, $out->in_reply_to]);
        $this->assertSame(['<earlier@example.com>', '<in-1@example.com>'], $out->references);
        $this->assertSame($sender->id, $out->sent_by);
    }

    public function test_a_new_email_needs_a_valid_address_and_subject(): void
    {
        $sender = $this->member(['email.view', 'email.send']);

        $this->actingAs($sender)->post('/mail', ['to' => 'not-an-email', 'body' => 'x'])->assertSessionHasErrors(['to', 'subject']);
        $this->actingAs($sender)->post('/mail', ['to' => 'Sami@Example.com', 'subject' => 'مرحبا', 'body' => 'نص'])->assertRedirect();
        $this->assertSame(['sami@example.com'], EmailMessage::query()->where('direction', 'out')->sole()->to);
    }

    public function test_attachments_are_served_safely_and_only_with_permission(): void
    {
        Storage::fake('local');
        $email = $this->incoming();
        Storage::disk('local')->put('attachments/1/a', '%PDF-1.4');
        Storage::disk('local')->put('attachments/1/b', '<script>alert(1)</script>');
        $pdf = $email->attachments()->create(['path' => 'attachments/1/a', 'original_name' => 'cv.pdf', 'mime' => 'application/pdf', 'size' => 8]);
        $html = $email->attachments()->create(['path' => 'attachments/1/b', 'original_name' => 'x.html', 'mime' => 'text/html', 'size' => 25]);

        $this->actingAs($this->member([]))->get("/attachments/{$pdf->id}")->assertForbidden();

        $reader = $this->member(['email.view']);
        $res = $this->actingAs($reader)->get("/attachments/{$pdf->id}")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $res->headers->get('Content-Disposition'));

        // HTML is never rendered on our origin: it downloads, sandboxed
        $res = $this->actingAs($reader)->get("/attachments/{$html->id}")->assertOk();
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
    }

    public function test_only_a_failed_email_can_be_retried(): void
    {
        $sender = $this->member(['email.view', 'email.send']);
        $queued = EmailMessage::query()->create(['direction' => 'out', 'status' => 'queued', 'to' => ['a@example.com'], 'subject' => 's', 'body_text' => 'b']);

        $this->actingAs($sender)->post("/mail/{$queued->id}/retry")->assertStatus(422);
        $this->actingAs($sender)->post("/mail/{$this->incoming()->id}/retry")->assertStatus(422);
    }
}
