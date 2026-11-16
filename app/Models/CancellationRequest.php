<?php

namespace App\Models;

use App\Enums\CancellationReason;
use App\Enums\CancellationRequesterRole;
use App\Enums\CancellationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's request to cancel a booking, or a provider's inability to fulfil it (Req 23, 24; slice-6-contract.md §4).
 * `open_booking_id` mirrors `booking_id` while under review: one pending request per booking.
 */
class CancellationRequest extends Model
{
    protected $fillable = [
        'reference', 'booking_id', 'requester_id', 'requester_role', 'reason_code', 'reason_text', 'status',
        'decided_by', 'decision_reason', 'internal_note', 'decided_at', 'withdrawn_at', 'open_booking_id',
    ];

    protected function casts(): array
    {
        return [
            'requester_role' => CancellationRequesterRole::class,
            'reason_code' => CancellationReason::class,
            'status' => CancellationStatus::class,
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
