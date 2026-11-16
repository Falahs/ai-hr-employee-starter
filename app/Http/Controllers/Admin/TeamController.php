<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الفريق وصلاحياتهم.
 *
 * - العضو الجديد يبدأ بلا صلاحيات، وبكلمة مرور مؤقتة تظهر للأدمن مرة واحدة
 *   ويُجبر العضو على تغييرها في أول دخول.
 * - الأدمن لا يستطيع أن يُسقط نفسه ولا آخر أدمن نشط، فلا ينقفل النظام على أحد.
 * - كل تغيير في الصلاحيات يُسجَّل: من غيّر، وماذا، ومتى.
 */
class TeamController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/team/Index', [
            'members' => User::query()->orderByDesc('is_active')->orderBy('name')
                ->get()->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                    'is_admin' => $u->is_admin, 'is_active' => $u->is_active,
                    'permissions_count' => count($u->effectivePermissions()),
                    'must_change_password' => $u->must_change_password,
                    'last_login_at' => $u->last_login_at?->toIso8601String(),
                ]),
            'catalog' => $this->catalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        $temporary = $this->temporaryPassword();
        $user = User::query()->create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Hash::make($temporary)]);
        $user->forceFill(['email_verified_at' => now(), 'must_change_password' => true, 'permissions' => []])->save();

        AuditLog::record('team.member_created', $request->user(), ['email' => $user->email], $user);

        return to_route('admin.team.edit', $user)->with('temporary_password', $temporary);
    }

    public function edit(User $member): Response
    {
        return Inertia::render('admin/team/Edit', [
            'member' => [
                'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
                'is_admin' => $member->is_admin, 'is_active' => $member->is_active,
                'must_change_password' => $member->must_change_password,
                'last_login_at' => $member->last_login_at?->toIso8601String(),
                'permissions' => $member->permissions ?? [],
            ],
            'is_self' => auth()->id() === $member->id,
            'catalog' => $this->catalog(),
        ]);
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'is_admin' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_map(fn (Permission $p) => $p->value, Permission::cases()))],
        ]);

        $this->guardAdminLockout($request->user(), $member, (bool) $data['is_admin'], (bool) $data['is_active']);

        $before = $this->snapshot($member);
        $permissionsBefore = $member->effectivePermissions();
        $member->forceFill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'is_admin' => (bool) $data['is_admin'],
            'is_active' => (bool) $data['is_active'],
            'permissions' => array_values(array_unique($data['permissions'] ?? [])),
        ])->save();

        $after = $this->snapshot($member);
        $diff = array_filter($after, fn ($v, $k) => $v !== ($before[$k] ?? null), ARRAY_FILTER_USE_BOTH);
        if ($diff) {
            AuditLog::record('team.member_updated', $request->user(), ['before' => array_intersect_key($before, $diff), 'after' => $diff], $member);
        }
        // an old answer may quote emails this member can no longer read, and text cannot be filtered after the fact
        if (array_diff($permissionsBefore, $member->effectivePermissions())) {
            ChatSession::clearFor($member, $request->user(), 'permissions removed');
        }

        return back()->with('status', 'saved');
    }

    public function resetPassword(Request $request, User $member): RedirectResponse
    {
        abort_if($request->user()->is($member), 422, __('ui.admin.team.errors.reset_self'));

        $temporary = $this->temporaryPassword();
        $member->forceFill(['password' => Hash::make($temporary), 'must_change_password' => true, 'remember_token' => Str::random(60)])->save();
        AuditLog::record('team.password_reset', $request->user(), [], $member);

        return back()->with('temporary_password', $temporary);
    }

    private function guardAdminLockout(User $actor, User $member, bool $isAdmin, bool $isActive): void
    {
        if ($actor->is($member) && (! $isAdmin || ! $isActive)) {
            throw ValidationException::withMessages(['is_admin' => __('ui.admin.team.errors.self_lockout')]);
        }

        $stillAdmin = $isAdmin && $isActive;
        $otherAdmins = User::query()->where('is_admin', true)->where('is_active', true)->whereKeyNot($member->id)->exists();
        if (! $stillAdmin && $member->is_admin && ! $otherAdmins) {
            throw ValidationException::withMessages(['is_admin' => __('ui.admin.team.errors.last_admin')]);
        }
    }

    private function snapshot(User $u): array
    {
        $perms = $u->permissions ?? [];
        sort($perms);

        return ['name' => $u->name, 'email' => $u->email, 'is_admin' => $u->is_admin, 'is_active' => $u->is_active, 'permissions' => $perms];
    }

    /** Readable but strong: 16 characters, mixed case and digits, no look-alike characters. */
    private function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        do {
            $p = '';
            for ($i = 0; $i < 16; $i++) {
                $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (! preg_match('/[A-Z]/', $p) || ! preg_match('/[a-z]/', $p) || ! preg_match('/\d/', $p));

        return $p;
    }

    private function catalog(): array
    {
        return array_map(fn (Permission $p) => ['value' => $p->value], Permission::cases());
    }
}
