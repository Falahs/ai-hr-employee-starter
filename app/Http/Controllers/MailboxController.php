<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * صندوق الإيميل: الوارد والصادر، فتح إيميل ومرفقاته، والرد أو كتابة إيميل جديد.
 *
 * الإرسال لا يتم هنا: الإيميل ينكتب في «طابور الإرسال» (status=queued)، والأمر hr:mail-send
 * يرسله كل دقيقة. فلو تعطّل خادم البريد، الواجهة ما تتعطّل معه.
 */
class MailboxController extends Controller
{
    /** Shown in the browser; anything else downloads. An HTML attachment is never rendered on our origin. */
    private const INLINE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewEmail->value);
        $box = $request->query('box') === 'out' ? 'out' : 'in';
        $q = trim((string) $request->query('q', ''));

        // lower() on both sides: works the same on PostgreSQL and on the SQLite the tests use
        $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
        $emails = EmailMessage::query()->where('direction', $box)->withCount('attachments')
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w
                ->whereRaw('lower(subject) like ?', [$like])
                ->orWhereRaw('lower(from_email) like ?', [$like])
                ->orWhereRaw('lower(from_name) like ?', [$like])))
            ->latest('id')->paginate(25, ['id', 'direction', 'status', 'from_email', 'from_name', 'to', 'subject', 'read_at', 'created_at', 'sent_at'])
            ->withQueryString();

        return Inertia::render('mail/Index', [
            'box' => $box,
            'q' => $q,
            'emails' => $emails,
            'unread' => EmailMessage::query()->where('direction', 'in')->whereNull('read_at')->count(),
            'mailbox' => [
                'address' => Setting::get('mail.address'),
                'receiving' => Setting::bool('mail.receiving_enabled'),
                'sending' => Setting::bool('mail.sending_enabled'),
            ],
        ]);
    }

    public function show(Request $request, EmailMessage $email): Response
    {
        Gate::authorize(Permission::ViewEmail->value);
        if ($email->direction === 'in' && ! $email->read_at) {
            $email->forceFill(['read_at' => now()])->save();
        }
        $email->load(['attachments:id,email_message_id,original_name,mime,size', 'sender:id,name']);

        // the conversation: this email, what it answers, and the replies to it
        $ids = array_filter([$email->message_id, $email->in_reply_to]);
        $thread = $ids ? EmailMessage::query()->whereKeyNot($email->id)
            ->where(fn ($w) => $w->whereIn('message_id', $ids)->orWhereIn('in_reply_to', $ids))
            ->orderBy('id')->get(['id', 'direction', 'status', 'from_email', 'from_name', 'subject', 'created_at']) : collect();

        return Inertia::render('mail/Show', [
            'email' => [
                ...$email->only(['id', 'direction', 'status', 'from_email', 'from_name', 'to', 'subject', 'body_text', 'error', 'created_at', 'sent_at']),
                'refused' => array_map(fn ($r) => ['name' => $r['name'], 'reason' => __('ui.upload.reason.'.$r['reason'])], $email->refused_attachments ?? []),
                'sender' => $email->sender?->name,
                'attachments' => $email->attachments->map(fn (EmailAttachment $a) => [
                    'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
                    'inline' => in_array($a->mime, self::INLINE_TYPES, true),
                ]),
            ],
            'thread' => $thread,
            'can_send' => $request->user()->can(Permission::SendEmail->value),
        ]);
    }

    public function compose(Request $request): Response
    {
        Gate::authorize(Permission::SendEmail->value);

        return Inertia::render('mail/Compose', ['sending' => Setting::bool('mail.sending_enabled')]);
    }

    /** A new email, or a reply when reply_to names the email it answers. */
    public function send(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SendEmail->value);
        $data = $request->validate([
            'reply_to' => ['nullable', 'integer', 'exists:email_messages,id'],
            'to' => ['required_without:reply_to', 'nullable', 'email', 'max:255'],
            'subject' => ['required_without:reply_to', 'nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $parent = isset($data['reply_to']) ? EmailMessage::query()->find($data['reply_to']) : null;
        if ($parent) {
            // a reply goes back to whoever wrote, in the same thread
            $to = $parent->direction === 'in' ? $parent->from_email : ($parent->to[0] ?? null);
            $subject = preg_match('/^\s*(re|رد)\s*:/iu', (string) $parent->subject) ? $parent->subject : 'Re: '.$parent->subject;
            $thread = ['in_reply_to' => $parent->message_id, 'references' => array_values(array_filter([...($parent->references ?? []), $parent->message_id]))];
        } else {
            $to = strtolower($data['to']);
            $subject = $data['subject'];
            $thread = ['in_reply_to' => null, 'references' => []];
        }
        abort_unless($to, 422);

        $email = EmailMessage::query()->create([
            'direction' => 'out', 'status' => 'queued', 'to' => [$to], 'subject' => $subject, 'body_text' => $data['body'], ...$thread,
        ]);
        $email->forceFill(['sent_by' => $request->user()->id])->save();
        AuditLog::record('mail.queued', $request->user(), ['to' => $to, 'reply_to' => $parent?->id], $email);

        return to_route('mail.show', $email)->with('status', 'queued');
    }

    /** A failed email goes back into the queue, from the first attempt. */
    public function retry(Request $request, EmailMessage $email): RedirectResponse
    {
        Gate::authorize(Permission::SendEmail->value);
        abort_unless($email->direction === 'out' && $email->status === 'failed', 422);
        $email->forceFill(['status' => 'queued', 'send_attempts' => 0, 'error' => null])->save();
        AuditLog::record('mail.retried', $request->user(), [], $email);

        return back()->with('status', 'queued');
    }

    public function attachment(Request $request, EmailAttachment $attachment): StreamedResponse
    {
        Gate::authorize(Permission::ViewEmail->value);

        $inline = in_array($attachment->mime, self::INLINE_TYPES, true) && ! $request->boolean('download');
        $headers = [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            // Chrome will not show a PDF in a sandboxed response; its viewer is isolated from the page anyway,
            // so a PDF may only not be framed by another site. Everything else runs sandboxed.
            'Content-Security-Policy' => $inline && $attachment->mime === 'application/pdf'
                ? "frame-ancestors 'none'"
                : "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ];

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, $headers, $inline ? 'inline' : 'attachment');
    }
}
