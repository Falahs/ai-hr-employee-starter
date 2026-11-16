<?php

namespace App\Services\Mail;

use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;

/**
 * One received email, parsed from its raw source into what Intake::receive() takes. Everything here is
 * the sender's data: the body is kept as plain text only (HTML is reduced to text, never rendered), file
 * names are cleaned, and the type of an attachment is read from its bytes, not from what the sender claims.
 */
class InboundMessage
{
    /** Per email: more than this is not an application, and a mailbox should not fill the disk. */
    public const MAX_ATTACHMENTS = 10;

    public const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    public const MAX_BODY_CHARS = 50000;

    /**
     * @return array{message_id: ?string, in_reply_to: ?string, references: list<string>, from_email: ?string, from_name: ?string,
     *               to: list<string>, subject: ?string, body: string, auto: ?string,
     *               attachments: list<array{name: string, mime: string, content: string}>, dropped: list<string>}
     */
    public static function parse(string $raw): array
    {
        /** @var Message $m */
        $m = (new MailMimeParser)->parse($raw, false);

        $from = $m->getHeader(HeaderConsts::FROM);
        $to = $m->getHeader(HeaderConsts::TO);

        $attachments = [];
        $dropped = [];
        foreach ($m->getAllAttachmentParts() as $part) {
            $name = self::cleanName((string) ($part->getFilename() ?: 'attachment'));
            $content = (string) $part->getContent();
            if ($content === '' && $part->getContentType() === 'text/plain' && ! $part->getFilename()) {
                continue; // an inline text part, not a file
            }
            if (count($attachments) >= self::MAX_ATTACHMENTS || strlen($content) > self::MAX_ATTACHMENT_BYTES) {
                $dropped[] = $name;

                continue;
            }
            $attachments[] = ['name' => $name, 'mime' => self::sniff($content, (string) $part->getContentType()), 'content' => $content];
        }

        $body = $m->getTextContent();
        if ($body === null || trim($body) === '') {
            $body = self::htmlToText((string) $m->getHtmlContent());
        }

        return [
            'message_id' => self::ids($m, HeaderConsts::MESSAGE_ID)[0] ?? null,
            'in_reply_to' => self::ids($m, HeaderConsts::IN_REPLY_TO)[0] ?? null,
            'references' => self::ids($m, HeaderConsts::REFERENCES),
            'from_email' => $from?->getEmail() ? strtolower(trim($from->getEmail())) : null,
            'from_name' => $from?->getPersonName() ?: null,
            'to' => $to ? array_values(array_filter(array_map(fn ($a) => strtolower((string) $a->getEmail()), $to->getAddresses()))) : [],
            'subject' => ($s = $m->getSubject()) !== null ? mb_substr(trim($s), 0, 250) : null,
            'body' => mb_substr(trim(str_replace("\r\n", "\n", (string) $body)), 0, self::MAX_BODY_CHARS),
            'auto' => self::automatic($m, $from?->getEmail()),
            'attachments' => $attachments,
            'dropped' => $dropped,
        ];
    }

    /**
     * Why this message was sent by a machine, or null for a person: auto-replies, out-of-office notices
     * and bounces must not become applications, nor be answered (two auto-responders would loop).
     */
    private static function automatic(Message $m, ?string $from): ?string
    {
        $auto = strtolower(trim((string) $m->getHeaderValue('Auto-Submitted')));
        if ($auto !== '' && $auto !== 'no') {
            return 'auto-submitted';
        }
        if (in_array(strtolower(trim((string) $m->getHeaderValue('Precedence'))), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return 'precedence';
        }
        if ($m->getHeader('X-Autoreply') || $m->getHeader('X-Autorespond')) {
            return 'auto-reply';
        }
        if ($from && preg_match('/^(mailer-daemon|postmaster|no-?reply)@/i', $from)) {
            return 'system-sender';
        }

        return null;
    }

    /** Message ids with their angle brackets, as stored in email_messages. */
    private static function ids(Message $m, string $header): array
    {
        $raw = (string) $m->getHeader($header)?->getRawValue();
        preg_match_all('/<[^<>\s]+>/', $raw, $found);

        return array_values(array_unique(array_map(fn ($id) => mb_substr($id, 0, 250), $found[0])));
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return mb_substr(trim($name), 0, 200) ?: 'attachment';
    }

    /** The type from the bytes; the sender's label only when the bytes say nothing more specific. */
    private static function sniff(string $content, string $claimed): string
    {
        $real = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        // a .docx is a zip to finfo; trust the claim only when it is that same family
        if ($real === 'application/zip' && str_contains($claimed, 'openxmlformats')) {
            return $claimed;
        }

        return $real !== '' && $real !== 'application/octet-stream' ? $real : ($claimed ?: 'application/octet-stream');
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $html = preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h\d)>/i', "\n", $html) ?? '';

        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
