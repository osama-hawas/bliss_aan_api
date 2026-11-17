<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * `not_required` while `payments.booking_payment_required` is off (D113). Slice 10 (D185): a paid booking is `paid`,
 * then `partially_refunded` / `refunded` as refunds settle.
 */
enum BookingPaymentStatus: string
{
    use HasLabel;

    case NotRequired = 'not_required';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
