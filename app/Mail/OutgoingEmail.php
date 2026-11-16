<?php

namespace App\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\AgentMailer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * A message a team member wrote in the inbox, sent as written. In-Reply-To and References keep it in the
 * sender's own thread, and the Message-ID is chosen here and stored, so the reply finds its way back.
 */
class OutgoingEmail extends Mailable
{
    public function __construct(public EmailMessage $msg, public string $messageId) {}

    public function envelope(): Envelope
    {
        $from = AgentMailer::from();

        return new Envelope(
            from: new Address($from['address'], $from['name']),
            to: array_values($this->msg->to ?? []),
            subject: (string) $this->msg->subject,
        );
    }

    public function headers(): Headers
    {
        $bare = fn (?string $id) => $id ? trim($id, '<> ') : null;

        return new Headers(
            messageId: $bare($this->messageId),
            references: array_values(array_filter(array_map(fn ($r) => $r ? '<'.$bare($r).'>' : null, $this->msg->references ?? []))),
            text: array_filter(['In-Reply-To' => $this->msg->in_reply_to ? '<'.$bare($this->msg->in_reply_to).'>' : null]),
        );
    }

    public function content(): Content
    {
        // plain text as written, plus the same text in an HTML part that only sets the direction
        return new Content(view: 'mail.outgoing', text: 'mail.outgoing-text', with: ['body' => (string) $this->msg->body_text]);
    }
}
