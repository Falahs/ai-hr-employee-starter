<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إيميل واحد، وارد (direction=in) أو صادر (out). الوارد يجي من صندوق الوكيل (hr:mail-fetch)،
 * والصادر ينكتب من الواجهة ويطلع من الطابور (hr:mail-send).
 */
class EmailMessage extends Model
{
    protected $fillable = ['direction', 'status', 'message_id', 'in_reply_to', 'references', 'from_email', 'from_name',
        'to', 'subject', 'body_text', 'imap_uid', 'error'];

    protected function casts(): array
    {
        return ['references' => 'array', 'to' => 'array', 'refused_attachments' => 'array', 'read_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** The email this one answers, when we have it. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to', 'message_id');
    }
}
