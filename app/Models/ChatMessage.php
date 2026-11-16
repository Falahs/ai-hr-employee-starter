<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a chat session: role is user or assistant. */
class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['role', 'content'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
