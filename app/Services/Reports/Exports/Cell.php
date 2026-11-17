<?php

namespace App\Services\Reports\Exports;

use App\Services\Reports\Cents;
use App\Services\Reports\StatusLabels;
use App\Support\RiyadhCalendar;
use Illuminate\Support\Carbon;

/**
 * Cell formats for exports (D322): Riyadh local time, request-language names, readable labels.
 */
final class Cell
{
    public static function dateTime(?string $utc): ?string
    {
        return $utc ? Carbon::parse($utc, 'UTC')->setTimezone(RiyadhCalendar::TIMEZONE)->format('Y-m-d H:i') : null;
    }

    /**
     * A translatable JSON column value ({"ar": …, "en": …}) in the request language, falling back to the other one.
     */
    public static function translated(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }
        $values = json_decode($json, true);
        if (! is_array($values)) {
            return is_string($values) ? $values : null;
        }

        return $values[app()->getLocale()] ?? (reset($values) ?: null);
    }

    public static function status(?string $status): string
    {
        return StatusLabels::label($status);
    }

    public static function bool(mixed $value): string
    {
        return __($value ? 'reports.yes' : 'reports.no');
    }

    /** Decimal money as stored ("5500.00"); Excel reads it as a number. */
    public static function money(mixed $amount): ?string
    {
        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }

    public static function cents(mixed $cents): ?string
    {
        return $cents === null ? null : Cents::toDecimal((int) $cents);
    }
}
