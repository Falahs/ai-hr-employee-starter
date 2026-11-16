<?php

namespace App\Rules;

use App\Services\Security\Outbound;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A host (or an https URL) the server may connect to for this kind of service (SECURITY_AUDIT ٩).
 * The port is checked here for a URL; for IMAP and SMTP the port field is checked on its own.
 */
class AllowedHost implements ValidationRule
{
    public function __construct(private string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$host, $port] = $this->kind === 'ai' ? Outbound::fromUrl((string) $value) : [(string) $value, config("outbound.ports.{$this->kind}")[0]];
        if ($why = Outbound::refusal($host, $port, $this->kind)) {
            $fail(__('ui.admin.settings.outbound.'.$why, ['hosts' => implode('، ', config('outbound.hosts')), 'ports' => implode('، ', config("outbound.ports.{$this->kind}"))]));
        }
    }
}
