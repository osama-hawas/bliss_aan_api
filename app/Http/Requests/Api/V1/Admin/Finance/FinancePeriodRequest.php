<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Riyadh-day period (D294); defaults and the day limit are applied by FinancePeriod.
 */
class FinancePeriodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
