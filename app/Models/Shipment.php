<?php

namespace App\Models;

use App\Enums\ShipmentMethod;
use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery of a store order (D201): through the carrier (`request_key` = the idempotency key sent to it) or by an
 * internal delivery officer (`assigned_admin_id`).
 *
 * @property ShipmentStatus $status
 * @property ShipmentMethod $method
 */
class Shipment extends Model
{
    protected $fillable = [
        'store_order_id', 'method', 'carrier', 'tracking_number', 'tracking_url', 'status', 'assigned_admin_id',
        'request_key', 'failure_reason', 'attempts', 'created_by', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'method' => ShipmentMethod::class,
            'status' => ShipmentStatus::class,
            'attempts' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
