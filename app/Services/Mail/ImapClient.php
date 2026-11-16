<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * The few IMAP commands the agent needs, nothing more: log in, open the inbox, list the UIDs after the
 * last one seen, and fetch messages whole without marking them read (BODY.PEEK), so the mailbox looks the
 * same to the people who also read it in Gmail.
 *
 * Messages are tracked by UID, not by the «unread» flag: a person opening a message in Gmail must not hide
 * it from the agent.
 */
class ImapClient
{
    private int $tag = 0;

    public function __construct(private ImapConnection $conn) {}

    public function login(string $user, string $password): void
    {
        $greeting = (string) $this->conn->line();
        if (! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('unexpected greeting');
        }
        $this->command('LOGIN '.$this->quote($user).' '.$this->quote($password));
    }

    /** @return array{uidvalidity: int, uidnext: int} */
    public function selectInbox(): array
    {
        $items = $this->command('SELECT INBOX');
        $out = ['uidvalidity' => 0, 'uidnext' => 0];
        foreach ($items as $it) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $it['text'], $m)) {
                $out['uidvalidity'] = (int) $m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $it['text'], $m)) {
                $out['uidnext'] = (int) $m[1];
            }
        }

        return $out;
    }

    /** UIDs strictly greater than $after, ascending. «n:*» always returns the last message, so it is filtered here. */
    public function uidsAfter(int $after): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH UID '.($after + 1).':*') as $it) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', trim($it['text']), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    if ($u !== '' && (int) $u > $after) {
                        $uids[] = (int) $u;
                    }
                }
            }
        }
        sort($uids);

        return array_values(array_unique($uids));
    }

    /** @return array<int, int> uid => size in bytes */
    public function sizes(array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $out = [];
        foreach ($this->command('UID FETCH '.implode(',', $uids).' (RFC822.SIZE)') as $it) {
            if (preg_match('/UID (\d+)/i', $it['text'], $u) && preg_match('/RFC822\.SIZE (\d+)/i', $it['text'], $s)) {
                $out[(int) $u[1]] = (int) $s[1];
            }
        }

        return $out;
    }

    /** The whole message as received (RFC 822), without setting \Seen. */
    public function fetchRaw(int $uid): ?string
    {
        foreach ($this->command('UID FETCH '.$uid.' (BODY.PEEK[])') as $it) {
            if ($it['literal'] !== null && preg_match('/UID '.$uid.'\b/i', $it['text'])) {
                return $it['literal'];
            }
        }

        return null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // the server may close first; nothing to do
        } finally {
            $this->conn->close();
        }
    }

    /**
     * Send one tagged command and read until its tagged answer. Untagged lines come back with any literal
     * they carry ({n} at the end of a line is followed by exactly n bytes).
     *
     * @return list<array{text: string, literal: ?string}>
     */
    private function command(string $cmd): array
    {
        $tag = 'a'.(++$this->tag);
        $this->conn->write($tag.' '.$cmd."\r\n");
        $items = [];

        while (true) {
            $line = $this->conn->line();
            if ($line === false) {
                throw new RuntimeException('connection closed');
            }
            if (str_starts_with($line, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $line)) {
                    // the server's reason only: the LOGIN line we sent is never echoed back
                    throw new RuntimeException(trim(substr($line, strlen($tag) + 1)));
                }

                return $items;
            }
            $item = ['text' => $line, 'literal' => null];
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $item['literal'] = $this->conn->read((int) $m[1]);
                // the rest of the response after the literal, usually just ")"
                $rest = $this->conn->line();
                $item['text'] .= $rest === false ? '' : $rest;
            }
            $items[] = $item;
        }
    }

    private function quote(string $s): string
    {
        return '"'.addcslashes($s, '"\\').'"';
    }
}
