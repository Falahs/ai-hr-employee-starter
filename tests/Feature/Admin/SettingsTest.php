<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function aiValues(array $over = []): array
    {
        return ['values' => array_merge([
            'ai__base_url' => 'https://ollama.com', 'ai__api_key' => '', 'ai__model' => 'glm-5.2:cloud',
            'ai__max_tokens' => '8192', 'ai__timeout_seconds' => '120',
            'ai__instructions' => 'أنت مساعد فريق التوظيف. أجب من الإيميلات فقط.',
        ], $over)];
    }

    public function test_members_cannot_open_or_change_settings(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/admin/settings')->assertForbidden();
        $this->actingAs($member)->put('/admin/settings/ai', $this->aiValues())->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
    }

    public function test_admin_sees_every_section(): void
    {
        $admin = $this->admin();
        foreach (['ai', 'mail'] as $section) {
            $this->actingAs($admin)->get("/admin/settings/{$section}")->assertOk();
        }
        $this->actingAs($admin)->get('/admin/settings/nope')->assertNotFound();
        $this->actingAs($admin)->get('/admin/settings/guard')->assertNotFound();   // the full system's sections are not here
    }

    public function test_unsaved_settings_show_their_defaults(): void
    {
        $props = $this->actingAs($this->admin())->get('/admin/settings/ai')->viewData('page')['props']['values'];

        $this->assertSame('https://ollama.com', $props['ai__base_url']);
        $this->assertSame('glm-5.2:cloud', $props['ai__model']);
        $this->assertStringContainsString('الإيميلات', $props['ai__instructions']);
        $this->assertSame('', $props['ai__api_key']);
    }

    public function test_api_key_is_stored_encrypted_never_returned_and_audited_without_value(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/settings/ai', $this->aiValues(['ai__api_key' => 'sk-test-SECRET-1234']))
            ->assertSessionHasNoErrors();

        $raw = DB::table('settings')->where('key', 'ai.api_key')->value('value');
        $this->assertNotSame('sk-test-SECRET-1234', $raw);
        $this->assertStringNotContainsString('SECRET', $raw);
        $this->assertSame('sk-test-SECRET-1234', Setting::get('ai.api_key'));

        $page = $this->actingAs($admin)->get('/admin/settings/ai')->getContent();
        $this->assertStringNotContainsString('SECRET', $page);
        $this->assertStringContainsString('1234', $page); // only the masked tail

        $log = AuditLog::query()->where('action', 'settings.updated')->latest('id')->first();
        $this->assertSame('(changed)', $log->changes['values']['ai.api_key']);
        $this->assertStringNotContainsString('SECRET', json_encode($log->changes));
    }

    public function test_empty_secret_keeps_the_stored_one(): void
    {
        $admin = $this->admin();
        Setting::put('ai.api_key', 'keep-me-9999', $admin);

        $this->actingAs($admin)->put('/admin/settings/ai', $this->aiValues(['ai__model' => 'other:cloud']))->assertSessionHasNoErrors();

        $this->assertSame('keep-me-9999', Setting::get('ai.api_key'));
        $this->assertSame('other:cloud', Setting::get('ai.model'));
    }

    public function test_invalid_values_are_rejected_under_their_field(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->put('/admin/settings/ai', $this->aiValues(['ai__base_url' => 'http://insecure.example', 'ai__max_tokens' => '10']))
            ->assertSessionHasErrors(['ai__base_url', 'ai__max_tokens']);

        // a provider off the server's allowed list is refused, https or not (SECURITY_AUDIT ٩)
        $this->actingAs($admin)
            ->put('/admin/settings/ai', $this->aiValues(['ai__base_url' => 'https://169.254.169.254']))
            ->assertSessionHasErrors(['ai__base_url']);
        $this->actingAs($admin)
            ->put('/admin/settings/ai', $this->aiValues(['ai__base_url' => 'https://evil.example.com']))
            ->assertSessionHasErrors(['ai__base_url']);
    }

    public function test_ai_connection_test_reports_the_model_and_saves_nothing(): void
    {
        Http::fake([
            'ollama.com/api/chat' => Http::sequence()
                ->push(['message' => ['content' => 'OK'], 'prompt_eval_count' => 5, 'eval_count' => 1])
                ->push(['error' => 'model not found'], 404),
        ]);

        $admin = $this->admin();
        $res = $this->actingAs($admin)->postJson('/admin/settings/ai/test', $this->aiValues(['ai__api_key' => 'try-this-key']))
            ->assertOk()->json('results');
        $this->assertSame([true], array_column($res, 'ok'));

        $res = $this->actingAs($admin)->postJson('/admin/settings/ai/test', $this->aiValues(['ai__model' => 'missing:cloud']))
            ->assertOk()->json('results');
        $this->assertFalse($res[0]['ok']);
        $this->assertNull(Setting::get('ai.api_key'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer try-this-key'));
    }
}
