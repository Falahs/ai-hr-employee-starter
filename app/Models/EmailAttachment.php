<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file that came with an incoming email. Stored on the private disk; it leaves only through MailboxController. */
class EmailAttachment extends Model
{
    protected $fillable = ['email_message_id', 'path', 'original_name', 'mime', 'size'];

    public function email(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
