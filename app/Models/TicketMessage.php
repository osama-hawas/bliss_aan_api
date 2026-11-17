<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'author_id', 'body', 'is_internal', 'client_uuid', 'fingerprint'];

    protected $hidden = ['fingerprint'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
