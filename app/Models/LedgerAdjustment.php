<?php

namespace App\Models;

use App\Enums\LedgerAdjustmentDirection;
use App\Enums\LedgerAdjustmentReason;
use App\Enums\LedgerAdjustmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff adjustment of a provider's ledger (slice-19-contract.md §3, D286). Never edited after a decision and never
 * deleted; posting writes one `provider_ledger_entries` row keyed `adjustment:{id}`.
 */
class LedgerAdjustment extends Model
{
    protected $fillable = [
        'reference', 'provider_id', 'direction', 'amount_cents', 'reason_code', 'reason', 'booking_id', 'payout_request_id',
        'payout_return_key', 'status', 'requires_approval', 'limits', 'requested_by', 'decided_by', 'decided_at',
        'decision_reason', 'posted_at', 'ledger_entry_key', 'idempotency_key', 'idempotency_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'idempotency_fingerprint'];

    /** Filled by the queries for display: the linked S11 payout's reference (no S11 model is imported). */
    public ?string $payoutReference = null;

    protected function casts(): array
    {
        return [
            'direction' => LedgerAdjustmentDirection::class,
            'reason_code' => LedgerAdjustmentReason::class,
            'status' => LedgerAdjustmentStatus::class,
            'amount_cents' => 'integer',
            'requires_approval' => 'boolean',
            'limits' => 'array',
            'decided_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function signedCents(): int
    {
        return $this->direction->signed($this->amount_cents);
    }

    public function entryKey(): string
    {
        return 'adjustment:'.$this->id;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
