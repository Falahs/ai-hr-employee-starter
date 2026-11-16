<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The password policy: 12+ characters, letters and a number, and upper
 * and lower case only when every letter is Latin. Arabic letters have no case, so the old
 * mixedCase() rule refused every Arabic password.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function change(string $new): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::factory()->create())->from('/settings/password')->put('/settings/password', [
            'current_password' => 'password', 'password' => $new, 'password_confirmation' => $new,
        ]);
    }

    public function test_an_arabic_password_is_accepted(): void
    {
        $this->change('عبدالعزيز123456')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('عبدالعزيز123456', User::query()->sole()->password));
    }

    public function test_arabic_mixed_with_lowercase_english_is_accepted(): void
    {
        $this->change('aziz-عبدالعزيز-2026')->assertSessionHasNoErrors();
    }

    public function test_an_english_password_still_needs_both_cases(): void
    {
        $this->change('lowercase-only-2026')->assertSessionHasErrors(['password' => __('ui.password.latin_mixed')]);
        $this->change('Mixed-Case-2026')->assertSessionHasNoErrors();
    }

    public function test_length_letters_and_a_number_are_still_required(): void
    {
        $this->change('عبدالعزيز12')->assertSessionHasErrors('password');            // 11 characters
        $this->change('عبدالعزيزالزامل')->assertSessionHasErrors('password');        // no number
        $this->change('123456789012')->assertSessionHasErrors('password');           // no letter
    }

    public function test_the_page_shows_the_rules_before_typing(): void
    {
        $page = $this->actingAs(User::factory()->create())->get('/settings/password')->assertOk()->viewData('page');

        $this->assertSame(__('ui.password.rules'), $page['props']['translations']['ui.password.rules'] ?? null);
        $this->assertStringContainsString("\$t('ui.password.rules')", file_get_contents(resource_path('js/pages/settings/Password.vue')));
    }
}
