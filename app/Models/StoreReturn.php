<?php

namespace App\Models;

use App\Enums\StoreReturnReason;
use App\Enums\StoreReturnStatus;
use App\Enums\UploadContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A client's return of delivered store order lines (S23, OD37). Lines are snapshots of the order lines; the refund is a
 * Slice 10 `refunds` row on the order's payment (`refund_id`). Photos are private `store_return_photo` media.
 *
 * @property StoreReturnStatus $status
 * @property StoreReturnReason $reason_code
 * @property Carbon|null $received_at
 */
class StoreReturn extends Model
{
    public const PHOTOS = 'store_return_photo';

    protected $fillable = [
        'reference', 'store_order_id', 'user_id', 'status', 'reason_code', 'note', 'policy_version_id', 'subtotal',
        'discount', 'refund_amount', 'refund_id', 'refund_attempts', 'refund_error', 'restocked', 'idempotency_key',
        'idempotency_fingerprint', 'approved_at', 'approved_by', 'rejected_at', 'rejected_by', 'reject_reason',
        'received_at', 'received_by', 'refunded_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreReturnStatus::class,
            'reason_code' => StoreReturnReason::class,
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'refund_attempts' => 'integer',
            'restocked' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'received_at' => 'datetime',
            'refunded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreReturnItem::class)->orderBy('id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(Media::class, 'attachable')->where('collection', UploadContext::StoreReturnPhoto->value)->orderBy('id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(ActivityEvent::class, 'subject');
    }
}
