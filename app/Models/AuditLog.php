<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who did what, when. Secret values are never written here, only that they changed. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public static function record(string $action, ?User $by, array $changes = [], ?Model $subject = null): void
    {
        static::query()->create([
            'user_id' => $by?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip' => request()?->ip(),
        ]);
    }
}
