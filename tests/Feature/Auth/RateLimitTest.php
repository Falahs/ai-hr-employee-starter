<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attempt limits: per device on every password form, and per account across
 * devices for the login, so many addresses guessing one account stop too.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_one_account_guessed_from_many_devices_is_stopped(): void
    {
        $this->freezeTime();   // the wait in the message is exact
        $u = User::factory()->create();
        for ($i = 1; $i <= 20; $i++) {   // a new address every try: the per-device limit never trips
            $this->device("203.0.113.$i")->post('/login', ['email' => $u->email, 'password' => 'wrong-guess'])->assertSessionHasErrors('email');
        }
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.account_throttled')->count());

        // the right password from yet another address is refused now, with the wait in minutes
        $this->device('203.0.113.99')->post('/login', ['email' => $u->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.throttle', ['seconds' => 3600, 'minutes' => 60])]);
        $this->assertGuest();

        // another account is not affected
        $other = User::factory()->create();
        $this->device('203.0.113.100')->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_changing_and_confirming_the_password_are_limited(): void
    {
        $u = User::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($u)->put('/settings/password', ['current_password' => "guess-$i", 'password' => 'New-Password-2026', 'password_confirmation' => 'New-Password-2026']);
        }
        $this->actingAs($u)->put('/settings/password', ['current_password' => 'password', 'password' => 'New-Password-2026', 'password_confirmation' => 'New-Password-2026'])
            ->assertStatus(429);

        $v = User::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($v)->post('/confirm-password', ['password' => "guess-$i"]);
        }
        $this->actingAs($v)->post('/confirm-password', ['password' => 'password'])->assertStatus(429);
    }

    public function test_a_limit_hit_from_the_interface_comes_back_as_a_message_under_the_field(): void
    {
        $u = User::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($u)->put('/settings/password', ['current_password' => "guess-$i", 'password' => 'x', 'password_confirmation' => 'x']);
        }

        $this->actingAs($u)->from('/settings/password')->withHeaders(['X-Inertia' => 'true'])
            ->put('/settings/password', ['current_password' => 'password', 'password' => 'x', 'password_confirmation' => 'x'])
            ->assertStatus(303)->assertRedirect('/settings/password')
            ->assertSessionHasErrors('current_password');
    }
}
