<?php

namespace App\Services\Payouts;

use App\Support\Money;

/**
 * Integer-cent arithmetic for the payouts ledger (D216). Decimal strings in, integers out; never floats.
 */
final class Cents
{
    public static function fromDecimal(string|int $amount): int
    {
        $normalized = Money::normalize((string) $amount); // "-12.34"
        $negative = str_starts_with($normalized, '-');
        $cents = (int) str_replace(['-', '.'], '', $normalized);

        return $negative ? -$cents : $cents;
    }

    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * `cents × bps / 10000`, rounded half away from zero to the cent (D219).
     */
    public static function share(int $cents, int $bps): int
    {
        $abs = intdiv(abs($cents) * $bps + 5000, 10000);

        return $cents < 0 ? -$abs : $abs;
    }

    /**
     * Basis points (percent × 100) from a percent like 15, "15", "12.5".
     */
    public static function bps(string|int|float $percent): int
    {
        return self::fromDecimal(is_float($percent) ? number_format($percent, 2, '.', '') : (string) $percent);
    }
}
