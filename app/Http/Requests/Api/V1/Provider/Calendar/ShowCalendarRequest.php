<?php

namespace App\Http\Requests\Api\V1\Provider\Calendar;

use App\Http\Requests\Concerns\ValidatesDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Her calendar range; `from` may be in the past (§5.4).
 */
class ShowCalendarRequest extends FormRequest
{
    use ValidatesDateRange;

    public function rules(): array
    {
        return $this->rangeRules();
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->checkRange($v, fromToday: false)];
    }
}
