<?php

namespace App\Http\Resources\Concerns;

use App\Support\Money;

/**
 * Integer cents (S11 / S19 ledger columns) as the API money object `{"amount": "1234.50", "currency": "SAR"}`.
 */
trait FormatsCents
{
    protected function cents(int|string|null $cents): ?array
    {
        return $cents === null ? null : Money::format(Money::fromCents((int) $cents));
    }
}
