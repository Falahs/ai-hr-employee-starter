<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function payload(User $m, array $over = []): array
    {
        return array_merge([
            'name' => $m->name, 'email' => $m->email, 'is_admin' => $m->is_admin, 'is_active' => $m->is_active,
            'permissions' => $m->permissions ?? [],
        ], $over);
    }

    public function test_members_cannot_reach_team_management(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($member)->get('/admin/team')->assertForbidden();
        $this->actingAs($member)->put("/admin/team/{$other->id}", $this->payload($other, ['is_admin' => true]))->assertForbidden();
        $this->assertFalse($other->fresh()->is_admin);
    }

    public function test_new_member_starts_with_nothing_and_a_one_time_temporary_password(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin)->post('/admin/team', ['name' => 'لينا', 'email' => 'Lina@Example.com']);
        $member = User::query()->where('email', 'lina@example.com')->firstOrFail();
        $res->assertRedirect("/admin/team/{$member->id}");

        $temporary = session('temporary_password');
        $this->assertNotEmpty($temporary);
        $this->assertTrue(Hash::check($temporary, $member->password));
        $this->assertTrue($member->must_change_password);
        $this->assertSame([], $member->effectivePermissions());
        $this->assertFalse($member->is_admin);

        // The next page shows it; the one after does not.
        $this->actingAs($admin)->get("/admin/team/{$member->id}")->assertSee($temporary);
        $this->actingAs($admin)->get("/admin/team/{$member->id}")->assertDontSee($temporary);
        $this->assertStringNotContainsString($temporary, json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_temporary_password_holder_can_only_reach_the_password_page(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['must_change_password' => true])->save();

        $this->actingAs($member)->get('/dashboard')->assertRedirect('/settings/password');
        $this->actingAs($member)->get('/settings/password')->assertOk();

        $this->actingAs($member)->put('/settings/password', [
            'current_password' => 'password', 'password' => 'NewSecret12345', 'password_confirmation' => 'NewSecret12345',
        ])->assertRedirect('/dashboard');

        $this->assertFalse($member->fresh()->must_change_password);
        $this->actingAs($member->fresh())->get('/dashboard')->assertOk();
    }

    public function test_weak_passwords_are_refused(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->put('/settings/password', [
            'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_permissions_are_saved_audited_and_gates_follow_them(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $this->actingAs($admin)->put("/admin/team/{$member->id}", $this->payload($member, ['permissions' => ['email.view']]))
            ->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame(['email.view'], $member->effectivePermissions());
        $this->assertTrue(Gate::forUser($member)->allows(Permission::ViewEmail->value));
        $this->assertFalse(Gate::forUser($member)->allows(Permission::SendEmail->value));
        $this->assertTrue(Gate::forUser($admin)->allows(Permission::SendEmail->value));

        $log = AuditLog::query()->where('action', 'team.member_updated')->latest('id')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['email.view'], $log->changes['after']['permissions']);
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $this->actingAs($admin)->put("/admin/team/{$member->id}", $this->payload($member, ['permissions' => ['everything']]))
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_losing_mail_access_clears_old_chat_answers(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $member->forceFill(['permissions' => ['email.view', 'chat.use']])->save();
        ChatSession::query()->create(['user_id' => $member->id, 'title' => 'quoted an email']);

        // adding a permission keeps the sessions
        $this->actingAs($admin)->put("/admin/team/{$member->id}", $this->payload($member->fresh(), ['permissions' => ['email.view', 'chat.use', 'email.send']]));
        $this->assertSame(1, ChatSession::query()->count());

        $this->actingAs($admin)->put("/admin/team/{$member->id}", $this->payload($member->fresh(), ['permissions' => ['chat.use']]));
        $this->assertSame(0, ChatSession::query()->count());
    }

    public function test_admin_cannot_lock_themselves_or_remove_the_last_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/team/{$admin->id}", $this->payload($admin, ['is_admin' => false]))->assertSessionHasErrors('is_admin');
        $this->actingAs($admin)->put("/admin/team/{$admin->id}", $this->payload($admin, ['is_active' => false]))->assertSessionHasErrors('is_admin');
        $this->assertTrue($admin->fresh()->is_admin);

        $second = $this->admin();
        $this->actingAs($admin)->put("/admin/team/{$second->id}", $this->payload($second, ['is_admin' => false]))->assertSessionHasNoErrors();
        $this->assertFalse($second->fresh()->is_admin);
    }

    public function test_deactivated_member_is_signed_out_and_cannot_log_in(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $member->forceFill(['permissions' => ['email.view']])->save();

        $this->actingAs($admin)->put("/admin/team/{$member->id}", $this->payload($member->fresh(), ['is_active' => false]))->assertSessionHasNoErrors();

        $this->actingAs($member->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', ['email' => $member->email, 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_reset_password_issues_a_new_temporary_one(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $this->actingAs($admin)->post("/admin/team/{$member->id}/reset-password")->assertSessionHas('temporary_password');
        $this->assertTrue($member->fresh()->must_change_password);
        $this->assertFalse(Hash::check('password', $member->fresh()->password));

        $this->actingAs($admin)->post("/admin/team/{$admin->id}/reset-password")->assertStatus(422);
    }
}
