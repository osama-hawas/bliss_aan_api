<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Calendar dates are Asia/Riyadh dates; stored values are UTC (D50, §4). Riyadh has no daylight saving, so a day D
 * is exactly [D-1 21:00Z, D 21:00Z).
 */
final class RiyadhCalendar
{
    public const TIMEZONE = 'Asia/Riyadh';

    /**
     * UTC instant of 00:00 Riyadh on the date (`Y-m-d`).
     */
    public static function dayStart(string $date): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $date, self::TIMEZONE)->utc();
    }

    /**
     * UTC instant of 00:00 Riyadh on the next date: the exclusive end of the day.
     */
    public static function dayEnd(string $date): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $date, self::TIMEZONE)->addDay()->utc();
    }

    public static function today(): string
    {
        return Carbon::now(self::TIMEZONE)->format('Y-m-d');
    }

    public static function dateOf(DateTimeInterface $instant): string
    {
        return Carbon::instance($instant)->setTimezone(self::TIMEZONE)->format('Y-m-d');
    }

    /**
     * The last date an exclusive end touches: the Riyadh date of `end − 1 minute`.
     */
    public static function lastDateBefore(DateTimeInterface $end): string
    {
        return self::dateOf(Carbon::instance($end)->subMinute());
    }

    public static function isMidnight(DateTimeInterface $instant): bool
    {
        return Carbon::instance($instant)->setTimezone(self::TIMEZONE)->format('H:i:s') === '00:00:00';
    }
}
