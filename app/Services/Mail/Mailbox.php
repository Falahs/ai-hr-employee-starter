<?php

namespace App\Services\Mail;

/** The agent's inbox as the fetch command sees it. The IMAP one is the real thing; tests use a fake. */
interface Mailbox
{
    /** @return array{uidvalidity: int, uidnext: int} */
    public function open(): array;

    /**
     * Up to $limit messages with a UID above $after, oldest first. A message over $maxBytes comes back
     * with raw = null, so it is counted and skipped without being downloaded.
     *
     * @return list<array{uid: int, size: int, raw: ?string}>
     */
    public function after(int $after, int $limit, int $maxBytes): array;

    public function close(): void;
}
