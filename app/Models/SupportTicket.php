<?php

namespace App\Models;

use App\Enums\TicketRelatedType;
use App\Enums\TicketStatus;
use App\Enums\TicketTopic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's or provider's support ticket (slice-12b-contract.md). `related_reference` is filled by the service when
 * the ticket is shown; it is not a column.
 */
class SupportTicket extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'topic', 'subject', 'related_type', 'related_id', 'status', 'assigned_to',
        'last_message_at', 'idempotency_key', 'idempotency_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'idempotency_fingerprint'];

    public ?string $relatedReference = null;

    protected function casts(): array
    {
        return [
            'topic' => TicketTopic::class,
            'status' => TicketStatus::class,
            'related_type' => TicketRelatedType::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }

    /**
     * Messages a mobile user may read: never internal staff notes (D161).
     */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal', false)->orderBy('id');
    }
}
