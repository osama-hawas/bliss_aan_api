<?php

namespace App\Support;

/**
 * Saudi mobile numbers in E.164 (+9665XXXXXXXX). Accepts 05…, 5…, 9665…, 009665… and +9665….
 */
final class Phone
{
    public static function normalizeSaudiMobile(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', strtr($input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));

        $local = match (true) {
            str_starts_with($digits, '009665') => substr($digits, 5),
            str_starts_with($digits, '9665') => substr($digits, 3),
            str_starts_with($digits, '05') => substr($digits, 1),
            default => $digits,
        };

        return preg_match('/^5\d{8}$/', $local) ? '+966'.$local : null;
    }
}
