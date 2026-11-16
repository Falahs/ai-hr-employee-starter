<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Rules\AllowedHost;
use App\Services\Ai\OllamaClient;
use App\Services\Mail\AgentMailer;
use App\Services\Mail\MailboxProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إعدادات الأدمن: قسمان، الذكاء الاصطناعي وصندوق الإيميل. كل قسم يُحفظ وحده، وكل تغيير
 * يُسجَّل في سجل التدقيق؛ والقيم السرّية تُسجَّل «تغيّرت» فقط بلا قيمتها.
 *
 * تمرين: أضف قسمًا جديدًا (مثلًا ساعات العمل): مفاتيحه في Setting::DEFAULTS، وقواعده هنا في fields().
 */
class SettingsController extends Controller
{
    public const SECTIONS = ['ai', 'mail'];

    /** Field → validation rules, per section. A secret left empty keeps the stored value. */
    private function fields(string $section): array
    {
        $model = ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'];

        return match ($section) {
            'ai' => [
                'ai.base_url' => ['required', 'url:https', 'max:255', new AllowedHost('ai')],
                'ai.api_key' => ['nullable', 'string', 'max:500'],
                'ai.model' => ['required', ...array_slice($model, 1)],
                'ai.max_tokens' => ['required', 'integer', 'min:256', 'max:65536'],
                'ai.timeout_seconds' => ['required', 'integer', 'min:10', 'max:600'],
                'ai.instructions' => ['required', 'string', 'min:10', 'max:5000'],
            ],
            'mail' => [
                'mail.address' => ['nullable', 'email', 'max:255'],
                'mail.from_name' => ['required', 'string', 'max:120'],
                'mail.app_password' => ['nullable', 'string', 'max:200'],
                'mail.imap_host' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i', new AllowedHost('imap')],
                'mail.imap_port' => ['required', 'integer', Rule::in(config('outbound.ports.imap'))],
                'mail.smtp_host' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i', new AllowedHost('smtp')],
                'mail.smtp_port' => ['required', 'integer', Rule::in(config('outbound.ports.smtp'))],
                'mail.sending_enabled' => ['required', 'boolean'],
                'mail.receiving_enabled' => ['required', 'boolean'],
            ],
            default => abort(404),
        };
    }

    /** What the mailbox is doing: what waits to go out (all of it leaves when sending is turned on), and the last read. */
    private function mailStatus(): array
    {
        $out = EmailMessage::query()->where('direction', 'out')->whereIn('status', ['queued', 'sending', 'failed'])
            ->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'queued' => (int) ($out['queued'] ?? 0) + (int) ($out['sending'] ?? 0),
            'failed' => (int) ($out['failed'] ?? 0),
            'last_sent_at' => EmailMessage::query()->where('direction', 'out')->where('status', 'sent')->max('sent_at'),
            'fetch' => Cache::get('mail.fetch.status'),
            'configured' => AgentMailer::configured(),
        ];
    }

    public function edit(string $section = 'ai'): Response
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        $values = [];
        foreach (array_keys($this->fields($section)) as $key) {
            $values[str_replace('.', '__', $key)] = in_array($key, Setting::SECRETS, true) ? '' : (string) (Setting::get($key) ?? '');
        }

        return Inertia::render('admin/Settings', [
            'section' => $section,
            'sections' => self::SECTIONS,
            'values' => $values,
            'secrets' => collect(Setting::SECRETS)->mapWithKeys(fn ($k) => [str_replace('.', '__', $k) => Setting::masked($k)]),
            'status' => $section === 'mail' ? $this->mailStatus() : null,
        ]);
    }

    public function update(Request $request, string $section): RedirectResponse
    {
        $fields = $this->fields($section);

        $data = $this->validated($request, $fields);

        $changed = [];
        foreach (array_keys($fields) as $key) {
            $value = $data[$key] ?? null;
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = $value === null ? null : (string) $value;
            $isSecret = in_array($key, Setting::SECRETS, true);

            if ($isSecret && ($value === null || $value === '')) {
                continue; // empty secret field = keep the stored one
            }
            if ($value === Setting::get($key)) {
                continue;
            }
            Setting::put($key, $value, $request->user());
            $changed[$key] = $isSecret ? '(changed)' : $value;
        }

        if ($changed) {
            AuditLog::record('settings.updated', $request->user(), ['section' => $section, 'values' => $changed]);
        }

        return back()->with('status', $changed ? 'saved' : 'unchanged');
    }

    /** Try the provider with the values on screen (or the stored key if the field is empty). Nothing is saved. */
    public function testAi(Request $request, OllamaClient $client): JsonResponse
    {
        $v = $this->validated($request, array_intersect_key($this->fields('ai'),
            array_flip(['ai.base_url', 'ai.model', 'ai.api_key'])));

        $key = ($v['ai.api_key'] ?? '') !== '' ? $v['ai.api_key'] : (string) Setting::get('ai.api_key');
        $r = $client->withOverrides($v['ai.base_url'], $key)->chat($v['ai.model'], [
            ['role' => 'user', 'content' => 'Reply with the single word: OK'],
        ]);

        return response()->json(['results' => [['model' => $v['ai.model'], 'ok' => $r->ok(), 'ms' => $r->durationMs, 'error' => $r->error]]]);
    }

    public function testMail(Request $request, MailboxProbe $probe): JsonResponse
    {
        $fields = array_intersect_key($this->fields('mail'),
            array_flip(['mail.address', 'mail.app_password', 'mail.imap_host', 'mail.imap_port', 'mail.smtp_host', 'mail.smtp_port']));
        $fields['mail.address'] = ['required', 'email', 'max:255'];
        $v = $this->validated($request, $fields);

        $password = ($v['mail.app_password'] ?? '') !== '' ? $v['mail.app_password'] : (string) Setting::get('mail.app_password');
        $password = str_replace(' ', '', $password); // Google shows App Passwords in groups of four

        return response()->json([
            'imap' => $probe->imap($v['mail.imap_host'], (int) $v['mail.imap_port'], $v['mail.address'], $password),
            'smtp' => $probe->smtp($v['mail.smtp_host'], (int) $v['mail.smtp_port'], $v['mail.address'], $password),
        ]);
    }

    /**
     * Setting keys are dotted (ai.base_url) and the validator would read the dots as
     * nesting, so on the wire they travel as ai__base_url. Errors come back under the
     * same wire key, which is what the form binds to.
     *
     * @return array<string, mixed> validated values keyed by the real setting key
     */
    private function validated(Request $request, array $fields): array
    {
        $wire = fn (string $k) => str_replace('.', '__', $k);
        $rules = $names = [];
        foreach ($fields as $key => $r) {
            $rules[$wire($key)] = $r;
            $names[$wire($key)] = __('ui.admin.settings.field.'.$key);
        }
        $data = validator((array) $request->input('values', []), $rules, [], $names)->validate();

        $out = [];
        foreach (array_keys($fields) as $key) {
            if (array_key_exists($wire($key), $data)) {
                $out[$key] = $data[$wire($key)];
            }
        }

        return $out;
    }
}
