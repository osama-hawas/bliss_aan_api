<?php

namespace App\Models;

use App\Enums\PayoutRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A provider's request to transfer part of her available balance (D216, D221). The account is snapshotted.
 */
class PayoutRequest extends Model
{
    protected $fillable = [
        'reference', 'provider_id', 'amount_cents', 'status', 'holder_name', 'iban', 'iban_last4', 'bank_name_key',
        'transfer_reference', 'paid_transfer_reference', 'rejection_reason', 'internal_note', 'processed_by',
        'processed_at', 'idempotency_key', 'idempotency_fingerprint', 'open_provider_id',
    ];

    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return [
            'status' => PayoutRequestStatus::class,
            'amount_cents' => 'integer',
            'iban' => 'encrypted',
            'processed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(ActivityEvent::class, 'subject');
    }
}
