<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * The browser signs in through Inertia, so a plain redirect would keep the login page's route list
     * (guests get only theirs, config/ziggy.php) and the dashboard would open blank. Signing in and out
     * must answer with a full page load (409 + X-Inertia-Location), which writes the right list.
     */
    public function test_signing_in_and_out_from_the_browser_reloads_the_page()
    {
        $user = User::factory()->create();
        $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

        $in = $this->withHeaders($inertia)->post('/login', ['email' => $user->email, 'password' => 'password']);
        $in->assertStatus(409);
        $this->assertStringEndsWith('/dashboard', $in->headers->get('X-Inertia-Location'));
        $this->assertAuthenticated();
        $this->flushHeaders();

        // the full load after it carries the signed-in routes
        $this->assertStringContainsString('"dashboard"', $this->get('/dashboard')->assertOk()->getContent());

        $out = $this->withHeaders($inertia)->post('/logout');
        $out->assertStatus(409);
        $this->assertSame(url('/'), $out->headers->get('X-Inertia-Location'));
        $this->assertGuest();
        $this->flushHeaders();
        $this->assertStringNotContainsString('"dashboard"', $this->get('/login')->getContent());
    }
}
