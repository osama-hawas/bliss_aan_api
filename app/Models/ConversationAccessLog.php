<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff read of a conversation through a support ticket (Req 42, D260). Insert only: never updated or deleted.
 */
class ConversationAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['staff_id', 'ticket_id', 'conversation_id', 'review_log_id', 'reason', 'messages_count', 'before_message_id', 'ip'];

    protected function casts(): array
    {
        return ['messages_count' => 'integer', 'before_message_id' => 'integer', 'review_log_id' => 'integer'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
