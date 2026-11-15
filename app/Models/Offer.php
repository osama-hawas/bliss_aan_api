<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provider's priced reply to one request (Slice 5b). Immutable once sent (D6): a new offer supersedes it.
 */
class Offer extends Model
{
    protected $fillable = [
        'reference', 'service_request_id', 'provider_id', 'title', 'total', 'validity_days', 'valid_until', 'notes',
        'is_quick', 'status', 'policy_version_id', 'sent_at', 'withdrawn_at', 'closed_at', 'idempotency_key',
        'idempotency_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'total' => 'decimal:2',
            'validity_days' => 'integer',
            'is_quick' => 'boolean',
            'valid_until' => 'datetime',
            'sent_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The status a viewer sees: `active` past its validity is `expired`, before the expiry job stores it.
     */
    public function effectiveStatus(): OfferStatus
    {
        return $this->status === OfferStatus::Active && $this->valid_until->lte(now()) ? OfferStatus::Expired : $this->status;
    }

    public function isLive(): bool
    {
        return $this->effectiveStatus() === OfferStatus::Active;
    }

    /**
     * Stored `active` and still inside its validity.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', OfferStatus::Active)->where('valid_until', '>', now());
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('sort')->orderBy('id');
    }
}
