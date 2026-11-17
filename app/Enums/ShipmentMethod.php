<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * How staff deliver an order (D201): through the carrier integration or by an internal delivery officer.
 */
enum ShipmentMethod: string
{
    use HasLabel;

    case Carrier = 'carrier';
    case Internal = 'internal';
}
