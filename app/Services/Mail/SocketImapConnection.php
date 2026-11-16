<?php

namespace App\Services\Mail;

use RuntimeException;

/** TLS socket to the mailbox (port 993), certificate verified. */
class SocketImapConnection implements ImapConnection
{
    /** @var resource */
    private $fp;

    public function __construct(string $host, int $port, int $timeout = 30)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (! $fp) {
            throw new RuntimeException("connect: {$errstr}");
        }
        stream_set_timeout($fp, $timeout);
        $this->fp = $fp;
    }

    public function line(): string|false
    {
        $line = fgets($this->fp);
        if ($line === false && stream_get_meta_data($this->fp)['timed_out']) {
            throw new RuntimeException('timeout waiting for the server');
        }

        return $line;
    }

    public function read(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = fread($this->fp, min(65536, $n - strlen($out)));
            if ($chunk === false || $chunk === '') {
                if (feof($this->fp) || stream_get_meta_data($this->fp)['timed_out']) {
                    throw new RuntimeException('connection ended inside a message');
                }

                continue;
            }
            $out .= $chunk;
        }

        return $out;
    }

    public function write(string $data): void
    {
        fwrite($this->fp, $data);
    }

    public function close(): void
    {
        @fclose($this->fp);
    }
}
