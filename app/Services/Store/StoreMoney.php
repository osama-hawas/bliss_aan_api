<?php

namespace App\Services\Store;

/**
 * Integer-cent arithmetic for the store (never floats). Amounts are `decimal(12,2)` strings like "25.00".
 */
final class StoreMoney
{
    public static function cents(string|int|float|null $amount): int
    {
        if ($amount === null) {
            return 0;
        }
        $text = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '-+')), 2, '0');
        $cents = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function amount(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** round_half_up($cents × $numerator / $denominator), all integers (percent values are given in cents: 10.00 → 1000). */
    public static function mulDiv(int $cents, int $numerator, int $denominator): int
    {
        return intdiv($cents * $numerator * 2 + $denominator, $denominator * 2);
    }
}
