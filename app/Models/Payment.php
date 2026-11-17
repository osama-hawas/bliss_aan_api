<?php

namespace App\Models;

use App\Enums\FulfilmentStatus;
use App\Enums\PayableType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentRefundState;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One attempt to pay a payable (slice-10-contract.md §5). The status is written only by PaymentStateMachine::apply.
 * `payable_type` is a PayableType value resolved by PayableRegistry (not an Eloquent morph).
 */
class Payment extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'payable_type', 'payable_id', 'purpose', 'amount', 'currency', 'method', 'card',
        'saved_payment_method_id', 'gateway', 'gateway_reference', 'status', 'failure_code', 'next_action_url',
        'fulfilment_status', 'fulfilment_error', 'refunded_amount', 'idempotency_key', 'idempotency_fingerprint',
        'last_checked_at', 'expires_at', 'paid_at', 'failed_at', 'cancelled_at', 'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'payable_type' => PayableType::class,
            'purpose' => PaymentPurpose::class,
            'method' => PaymentMethodType::class,
            'status' => PaymentStatus::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'card' => 'array',
            'raw_response' => 'array',
            'last_checked_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedMethod(): BelongsTo
    {
        return $this->belongsTo(SavedPaymentMethod::class, 'saved_payment_method_id')->withTrashed();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /**
     * Offers only: the booking this payment confirmed.
     */
    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'payable_id');
    }

    public function amountCents(): int
    {
        return Money::toCents($this->amount);
    }

    public function refundedCents(): int
    {
        return Money::toCents($this->refunded_amount ?? '0');
    }

    /**
     * What staff may still refund (amount minus refunds in progress or done).
     */
    public function refundableCents(): int
    {
        return $this->status === PaymentStatus::Succeeded ? max(0, $this->amountCents() - $this->refundedCents()) : 0;
    }

    /**
     * Settled refunds only (the money that went back).
     */
    public function settledRefundCents(): int
    {
        return (int) $this->refunds->where('status', RefundStatus::Refunded)->sum(fn (Refund $r) => Money::toCents($r->amount));
    }

    public function refundState(): PaymentRefundState
    {
        $refunds = $this->refunds;
        if ($refunds->contains('status', RefundStatus::InProgress)) {
            return PaymentRefundState::InProgress;
        }
        $settled = $this->settledRefundCents();

        return match (true) {
            $settled <= 0 => PaymentRefundState::None,
            $settled >= $this->amountCents() => PaymentRefundState::Full,
            default => PaymentRefundState::Partial,
        };
    }
}
