<?php

namespace App\Support;

/**
 * Saudi IBAN helpers (slice-11-contract.md §7): `SA` + 2 check digits + 2-digit bank code + 18 alphanumeric account
 * characters, ISO 13616 mod-97 = 1. Never log the full value.
 */
final class SaudiIban
{
    public static function normalize(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }

    public static function isValid(string $value): bool
    {
        $iban = self::normalize($value);
        if (! preg_match('/^SA\d{2}\d{2}[A-Z0-9]{18}$/', $iban)) {
            return false;
        }

        // Move the first four characters to the end, letters → numbers (A = 10 … Z = 35), then mod 97 piecewise.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $digits = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    public static function bankCode(string $iban): string
    {
        return substr(self::normalize($iban), 4, 2);
    }

    public static function last4(string $iban): string
    {
        return substr(self::normalize($iban), -4);
    }

    public static function mask(string $last4): string
    {
        return 'SA•• •••• •••• •••• •••• '.$last4;
    }

    /**
     * @return array{key: string, ar: string, en: string}|null
     */
    public static function bank(string $bankCode): ?array
    {
        return config('saudi_banks.'.$bankCode);
    }

    /**
     * The localized bank name for a stored bank key, or null when the bank is unknown.
     */
    public static function bankName(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }
        foreach (config('saudi_banks') as $bank) {
            if ($bank['key'] === $key) {
                return $bank[app()->getLocale() === 'ar' ? 'ar' : 'en'];
            }
        }

        return null;
    }
}
