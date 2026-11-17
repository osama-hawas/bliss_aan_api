<?php

namespace App\Services\Reports;

use App\Support\Money;

/**
 * Integer cents ↔ API money for report totals (sums are taken in cents in SQL, never as floats).
 */
final class Cents
{
    /**
     * @return array{amount: string, currency: string}|null
     */
    public static function money(int|string|null $cents): ?array
    {
        if ($cents === null) {
            return null;
        }

        return Money::format(self::toDecimal((int) $cents));
    }

    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * SQL for the sum of a decimal(…, 2) column in cents.
     */
    public static function sumOfDecimal(string $column): string
    {
        return "COALESCE(ROUND(SUM({$column}) * 100), 0)";
    }

    /**
     * SQL for the sum of an integer cents column.
     */
    public static function sumOfCents(string $column): string
    {
        return "COALESCE(SUM({$column}), 0)";
    }
}
