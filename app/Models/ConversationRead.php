<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A participant's read marker: every message with id ≤ last_read_message_id counts as read by her.
 */
class ConversationRead extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'last_read_message_id'];

    protected function casts(): array
    {
        return ['last_read_message_id' => 'integer'];
    }
}
