<?php

namespace App\Models;

use App\Enums\Permission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * عضو الفريق. الأدمن يملك كل الصلاحيات، والعضو يملك اللي يعطيه الأدمن فقط (Permission).
 * الصلاحية تُفحص على الخادم (Gate)، والواجهة تخفي وتظهر للتسهيل فقط.
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** is_admin, is_active, permissions are never mass assignable: only the team screen sets them, field by field. */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Same as the column defaults, so a model fresh from create() already holds them
     * (otherwise is_active reads null until reloaded and the account looks deactivated).
     */
    protected $attributes = [
        'is_admin' => false,
        'is_active' => true,
        'must_change_password' => false,
        'permissions' => '[]',
        'locale' => 'ar',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->is_admin || in_array($permission->value, $this->effectivePermissions(), true);
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        if ($this->is_admin) {
            return array_map(fn (Permission $p) => $p->value, Permission::cases());
        }

        return array_values(array_intersect($this->permissions ?? [], array_map(fn (Permission $p) => $p->value, Permission::cases())));
    }
}
