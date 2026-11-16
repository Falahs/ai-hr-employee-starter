<?php

namespace App\Services\Mail;

/** The bytes between the IMAP client and the server, so the client can be tested against a scripted server. */
interface ImapConnection
{
    /** One line including its CRLF, or false when the server closed the connection. */
    public function line(): string|false;

    /** Exactly $n bytes (an IMAP literal). */
    public function read(int $n): string;

    public function write(string $data): void;

    public function close(): void;
}
