<?php

namespace App\Http\Requests\Concerns;

use App\Support\RiyadhCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * `from` / `to` Riyadh dates, inclusive, at most 62 dates (`to − from ≤ 61 days`, §5.4, §7).
 */
trait ValidatesDateRange
{
    public const MAX_RANGE_DAYS = 61;

    protected function rangeRules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ];
    }

    protected function checkRange(Validator $validator, bool $fromToday): void
    {
        if ($validator->errors()->hasAny(['from', 'to'])) {
            return;
        }

        $from = Carbon::createFromFormat('!Y-m-d', $this->input('from'));
        $to = Carbon::createFromFormat('!Y-m-d', $this->input('to'));

        if ($fromToday && $this->input('from') < RiyadhCalendar::today()) {
            $validator->errors()->add('from', __('validation.custom.range.from_past'));
        }
        if ($to->lt($from)) {
            $validator->errors()->add('to', __('validation.custom.range.to_before_from'));
        } elseif ((int) $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $validator->errors()->add('to', __('validation.custom.range.too_long'));
        }
    }
}
