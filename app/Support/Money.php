<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money as strings with 2 decimals (never floats): {"amount": "5500.00", "currency": "SAR"}.
 */
final class Money
{
    public static function normalize(string|int $amount): string
    {
        $amount = trim((string) $amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $whole = ltrim($m[2], '0') ?: '0';
        $fraction = str_pad($m[3] ?? '', 2, '0');
        $sign = $m[1] === '-' && ($whole !== '0' || $fraction !== '00') ? '-' : '';

        return "{$sign}{$whole}.{$fraction}";
    }

    /**
     * @return array{amount: string, currency: string}|null
     */
    public static function format(string|int|null $amount): ?array
    {
        if ($amount === null) {
            return null;
        }

        return ['amount' => self::normalize($amount), 'currency' => config('app.currency')];
    }

    /**
     * Slice 10: integer cents for calculations ("5500.50" → 550050). Never floats.
     */
    public static function toCents(string|int $amount): int
    {
        $normalized = self::normalize($amount);
        $negative = str_starts_with($normalized, '-');
        [$whole, $fraction] = explode('.', ltrim($normalized, '-'));
        $cents = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
