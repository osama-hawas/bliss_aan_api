<?php

namespace App\Services\Finance;

use App\Support\RiyadhCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A reporting period of Riyadh calendar days (D294): `from`–`to` inclusive, as UTC instants [start, end).
 * Default: the first day of the current Riyadh month → today.
 */
final readonly class FinancePeriod
{
    private function __construct(
        public string $from,
        public string $to,
        public Carbon $start,
        public Carbon $end,
    ) {}

    public static function make(?string $from, ?string $to, int $maxDays): self
    {
        $today = RiyadhCalendar::today();
        $to ??= $today;
        $from ??= Carbon::createFromFormat('!Y-m-d', $to)->startOfMonth()->format('Y-m-d');

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => [__('validation.before_or_equal', ['attribute' => 'from', 'date' => $to])]]);
        }
        $days = Carbon::createFromFormat('!Y-m-d', $from)->diffInDays(Carbon::createFromFormat('!Y-m-d', $to)) + 1;
        if ($days > $maxDays) {
            throw ValidationException::withMessages(['to' => [__('validation.max.numeric', ['attribute' => 'days', 'max' => $maxDays])]]);
        }

        return new self($from, $to, RiyadhCalendar::dayStart($from), RiyadhCalendar::dayEnd($to));
    }

    /**
     * @return list<string> every date of the period
     */
    public function dates(): array
    {
        $dates = [];
        for ($d = Carbon::createFromFormat('!Y-m-d', $this->from); $d->format('Y-m-d') <= $this->to; $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        return $dates;
    }
}
