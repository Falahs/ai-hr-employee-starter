<?php

namespace App\Services\Mail;

use App\Models\Setting;
use App\Services\Security\Outbound;

/** The mailbox in the settings, over IMAP (TLS, port 993 by default), logged in with the app password. */
class ImapMailbox implements Mailbox
{
    private ?ImapClient $client = null;

    public function open(): array
    {
        Outbound::check((string) Setting::get('mail.imap_host'), Setting::int('mail.imap_port'), 'imap');
        $this->client = new ImapClient(new SocketImapConnection((string) Setting::get('mail.imap_host'), Setting::int('mail.imap_port')));
        $this->client->login((string) Setting::get('mail.address'), (string) Setting::get('mail.app_password'));

        return $this->client->selectInbox();
    }

    public function after(int $after, int $limit, int $maxBytes): array
    {
        $uids = array_slice($this->client->uidsAfter($after), 0, $limit);
        $sizes = $this->client->sizes($uids);
        $out = [];
        foreach ($uids as $uid) {
            $size = $sizes[$uid] ?? 0;
            $out[] = ['uid' => $uid, 'size' => $size, 'raw' => $size > $maxBytes ? null : $this->client->fetchRaw($uid)];
        }

        return $out;
    }

    public function close(): void
    {
        $this->client?->logout();
        $this->client = null;
    }
}
