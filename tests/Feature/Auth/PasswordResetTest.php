<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // the link goes out through the agent mailbox, or not at all
        Setting::put('mail.address', 'careers@x.sa');
        Setting::put('mail.app_password', 'app-password');
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_without_a_mailbox_no_link_is_made_and_the_page_says_ask_the_admin()
    {
        Notification::fake();
        Setting::put('mail.app_password', null);
        $user = User::factory()->create();

        $this->assertFalse($this->get('/forgot-password')->viewData('page')['props']['mailbox']);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', __('ui.auth.forgot_page.no_mailbox'));
        Notification::assertNothingSent();
        $this->assertSame(0, \DB::table('password_reset_tokens')->count());
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Strong-Pass-2026',
                'password_confirmation' => 'Strong-Pass-2026',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }
}
