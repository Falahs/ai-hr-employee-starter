<?php

namespace App\Services\Mail;

use App\Services\Security\Outbound;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * يجرّب بيانات صندوق الوكيل قبل حفظها: دخول IMAP (الاستقبال) ودخول SMTP
 * (الإرسال)، بدون قراءة أي رسالة وبدون إرسال شيء.
 */
class MailboxProbe
{
    /** @return array{ok: bool, error: ?string} */
    public function imap(string $host, int $port, string $user, string $password): array
    {
        if ($why = Outbound::refusal($host, $port, 'imap')) {
            return ['ok' => false, 'error' => "refused: {$why}"];
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (! $fp) {
            return ['ok' => false, 'error' => "connect: {$errstr}"];
        }
        stream_set_timeout($fp, 15);

        try {
            if (! str_starts_with((string) fgets($fp), '* OK')) {
                return ['ok' => false, 'error' => 'unexpected greeting'];
            }
            fwrite($fp, 'a1 LOGIN '.$this->quote($user).' '.$this->quote($password)."\r\n");
            while (($line = fgets($fp)) !== false) {
                if (str_starts_with($line, 'a1 ')) {
                    $ok = str_starts_with($line, 'a1 OK');
                    fwrite($fp, "a2 LOGOUT\r\n");

                    // The server's reason, without echoing anything we sent.
                    return ['ok' => $ok, 'error' => $ok ? null : trim(substr($line, 3))];
                }
            }

            return ['ok' => false, 'error' => 'no answer to LOGIN'];
        } finally {
            fclose($fp);
        }
    }

    /** @return array{ok: bool, error: ?string} */
    public function smtp(string $host, int $port, string $user, string $password): array
    {
        if ($why = Outbound::refusal($host, $port, 'smtp')) {
            return ['ok' => false, 'error' => "refused: {$why}"];
        }
        try {
            $transport = new EsmtpTransport($host, $port, $port === 465);
            $transport->setUsername($user);
            $transport->setPassword($password);
            $transport->start();
            $transport->stop();

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function quote(string $s): string
    {
        return '"'.addcslashes($s, '"\\').'"';
    }
}
