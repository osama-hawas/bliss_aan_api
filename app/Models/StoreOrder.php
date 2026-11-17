<?php

namespace App\Models;

use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StoreOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A store order (Slice 9b). A snapshot: items, prices, promo, address and recipient never follow later changes. It is
 * the Slice 10 payable `store_order`; `payment_id` is the payment that confirmed it. Payment attempts are the
 * `payments` rows with this payable (`payments()`).
 *
 * @property StoreOrderStatus $status
 * @property Carbon|null $confirmed_at
 */
class StoreOrder extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'status', 'address', 'recipient_name', 'recipient_phone', 'subtotal', 'discount',
        'shipping_fee', 'total', 'vat_percent', 'vat_amount', 'promo', 'payment_id', 'idempotency_key',
        'idempotency_fingerprint', 'confirmed_at', 'expired_at', 'cancelled_at', 'cancel_reason', 'cancelled_by',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreOrderStatus::class,
            'address' => 'array',
            'promo' => 'array',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'vat_percent' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class)->orderBy('id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('id');
    }

    /** The live (pending / in transit) shipment, if any. */
    public function liveShipment(): HasOne
    {
        return $this->hasOne(Shipment::class)
            ->whereIn('status', [ShipmentStatus::Pending->value, ShipmentStatus::InTransit->value])
            ->latestOfMany();
    }

    /** The newest shipment (what the client sees). */
    public function latestShipment(): HasOne
    {
        return $this->hasOne(Shipment::class)->latestOfMany();
    }

    public function promoUsage(): HasOne
    {
        return $this->hasOne(PromoCodeUsage::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The payment that confirmed the order (Slice 10). */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Every payment attempt on this order (Slice 10 payable). */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Payment states that may still confirm this order: an open attempt (pending / requires action / unknown) or a
     * succeeded payment whose fulfilment has not run yet (Slice 10 retries it). Such an order must never expire.
     */
    public function scopeWithoutLivePayment(Builder $query): Builder
    {
        return $query->whereDoesntHave('payments', fn (Builder $payment) => self::livePayment($payment));
    }

    public function hasLivePayment(): bool
    {
        return self::livePayment($this->payments()->getQuery())->exists();
    }

    private static function livePayment(Builder $payment): Builder
    {
        return $payment->where(fn (Builder $q) => $q
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::RequiresAction->value, PaymentStatus::Unknown->value])
            ->orWhere(fn (Builder $paid) => $paid->where('status', PaymentStatus::Succeeded->value)->where('fulfilment_status', FulfilmentStatus::Pending->value)));
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(ActivityEvent::class, 'subject');
    }
}
