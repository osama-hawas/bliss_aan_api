<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Shipment states (`slice-9b-contract.md` §4.2). `pending` = the carrier's answer was unknown and the request is retried
 * with the same key.
 */
enum ShipmentStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::InTransit;
    }
}
