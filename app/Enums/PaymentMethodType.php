<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Slice 10: how she pays. The card brand (mada / visa / mastercard) lives in the payment's card snapshot.
 */
enum PaymentMethodType: string
{
    use HasLabel;

    case Card = 'card';
    case ApplePay = 'apple_pay';
    // Owner decision 2026-11-19: her wallet balance, no gateway.
    case Wallet = 'wallet';
}
