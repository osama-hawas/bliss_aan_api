<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shipment status change: from the carrier (sync, `external_event_id` = `{tracking}:{carrier_status}`) or by staff
 * (`staff:{uuid}`). Unique on `external_event_id`, so a repeated carrier status is stored once.
 */
class ShipmentEvent extends Model
{
    protected $fillable = ['shipment_id', 'external_event_id', 'status', 'carrier_status', 'note', 'occurred_at', 'raw', 'actor_id'];

    protected function casts(): array
    {
        return ['status' => ShipmentStatus::class, 'occurred_at' => 'datetime', 'raw' => 'array'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
