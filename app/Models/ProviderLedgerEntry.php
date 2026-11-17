<?php

namespace App\Models;

use App\Enums\LedgerEntryStatus;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only provider ledger (D218). Rows are never updated or deleted; `entry_key` makes every write idempotent.
 */
class ProviderLedgerEntry extends Model
{
    protected $fillable = [
        'provider_id', 'type', 'amount_cents', 'booking_id', 'payout_request_id', 'gross_cents', 'commission_cents',
        'commission_bps', 'available_at', 'entry_key', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'gross_cents' => 'integer',
            'commission_cents' => 'integer',
            'commission_bps' => 'integer',
            'available_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function status(): LedgerEntryStatus
    {
        return $this->available_at->isFuture() ? LedgerEntryStatus::Pending : LedgerEntryStatus::Available;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }
}
