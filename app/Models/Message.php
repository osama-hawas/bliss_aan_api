<?php

namespace App\Models;

use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chat message. Attachments are `media` rows; an offer card references the live offer (never a copy).
 */
class Message extends Model
{
    protected $fillable = ['conversation_id', 'sender_id', 'type', 'body', 'media_id', 'offer_id', 'client_uuid', 'fingerprint', 'flagged'];

    protected function casts(): array
    {
        return ['type' => MessageType::class, 'flagged' => 'boolean'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
