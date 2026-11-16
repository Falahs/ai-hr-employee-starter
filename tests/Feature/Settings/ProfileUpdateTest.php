<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/profile')->assertOk();
    }

    public function test_member_can_change_name_but_not_email(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'New Name', 'email' => 'other@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('member@example.com', $user->email);
    }

    public function test_member_cannot_delete_own_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertStatus(405);
        $this->assertNotNull($user->fresh());
    }
}
