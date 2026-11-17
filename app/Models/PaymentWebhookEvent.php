<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A received gateway webhook (D189), unique on (gateway, event_id) so a redelivery is a no-op.
 */
class PaymentWebhookEvent extends Model
{
    protected $fillable = ['gateway', 'event_id', 'type', 'gateway_reference', 'payload', 'received_at', 'processed_at', 'result'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
