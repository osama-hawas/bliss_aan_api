<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 5c has no payment step (D113): every booking is `not_required`. Payment states arrive with Slice 10.
 */
enum BookingPaymentStatus: string
{
    use HasLabel;

    case NotRequired = 'not_required';
}
