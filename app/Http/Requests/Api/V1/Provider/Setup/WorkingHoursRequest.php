<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole week at once; a weekday absent is closed. One range per day, opening before closing.
 */
class WorkingHoursRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'hours' => ['present', 'array', 'max:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i', 'after:hours.*.opens_at'],
        ];
    }
}
