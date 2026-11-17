<?php

namespace App\Models;

use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money going back for one payment (D187). Reserved `in_progress` before the gateway call, settled once.
 */
class Refund extends Model
{
    protected $fillable = [
        'payment_id', 'reference', 'kind', 'amount', 'reason', 'status', 'gateway_reference', 'failure_code',
        'initiated_by', 'cancellation_request_id', 'idempotency_key', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RefundKind::class,
            'status' => RefundStatus::class,
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function cancellationRequest(): BelongsTo
    {
        return $this->belongsTo(CancellationRequest::class);
    }
}
