<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The one place that decides whether the server may connect to a host the settings name (SECURITY_AUDIT ٩).
 * Used when the settings are saved, by the «test connection» buttons, and right before every real connection,
 * so a value stored any other way (an old row, a console edit) is held to the same rule.
 */
class Outbound
{
    /** The reason a destination is refused, or null when it is allowed. */
    public static function refusal(string $host, int $port, string $kind): ?string
    {
        $host = strtolower(trim($host, " .\t"));
        if ($host === '' || ! self::listed($host)) {
            return 'host_not_allowed';
        }
        if (! in_array($port, config("outbound.ports.{$kind}", []), true)) {
            return 'port_not_allowed';
        }
        if (! self::resolvesPublic($host)) {
            return 'private_address';
        }

        return null;
    }

    /** Throws when refused: for the code paths that connect. */
    public static function check(string $host, int $port, string $kind): void
    {
        if ($why = self::refusal($host, $port, $kind)) {
            throw new RuntimeException("outbound {$kind} to {$host}:{$port} refused ({$why})");
        }
    }

    /** host and port of an https base URL (the AI provider). */
    public static function fromUrl(string $url): array
    {
        $p = parse_url($url) ?: [];

        return [(string) ($p['host'] ?? ''), (int) ($p['port'] ?? (($p['scheme'] ?? '') === 'http' ? 80 : 443))];
    }

    private static function listed(string $host): bool
    {
        foreach (config('outbound.hosts', []) as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every address the name resolves to is public. A name that does not resolve passes here: there is no
     * address to protect, and the connection itself then fails. Looked up once a minute per host.
     */
    private static function resolvesPublic(string $host): bool
    {
        return Cache::remember("outbound.public.{$host}", 60, function () use ($host) {
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return false;
                }
            }

            return true;
        });
    }
}
