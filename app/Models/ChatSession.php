<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One independent conversation with its own memory: the assistant sees only this session's messages. */
class ChatSession extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /**
     * When a member's access narrows, their earlier answers may quote emails they can no longer read, and an
     * answer is text that cannot be filtered after the fact: the sessions go.
     */
    public static function clearFor(User $user, ?User $by, string $why): int
    {
        $n = static::query()->where('user_id', $user->id)->count();
        if ($n) {
            static::query()->where('user_id', $user->id)->delete();   // messages go with them (cascade)
            AuditLog::record('chat.cleared', $by, ['sessions' => $n, 'why' => $why], $user);
        }

        return $n;
    }
}
