<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Promo code kinds (D200): a percentage of the products subtotal or a fixed amount.
 */
enum PromoCodeType: string
{
    use HasLabel;

    case Percent = 'percent';
    case Fixed = 'fixed';
}
