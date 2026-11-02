<?php

namespace App\Http\Resources\Concerns;

use App\Support\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Formats from docs/03-api-conventions.md: ISO-8601 UTC dates and money objects.
 */
trait FormatsApiValues
{
    protected function dateTime(?DateTimeInterface $value): ?string
    {
        return $value ? Carbon::instance($value)->utc()->toIso8601ZuluString() : null;
    }

    protected function date(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }

    protected function money(string|int|null $amount): ?array
    {
        return Money::format($amount);
    }
}
