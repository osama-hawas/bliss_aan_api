<?php

namespace App\Http\Requests\Api\V1\Admin\Payouts;

use App\Enums\PayoutRequestStatus;
use App\Services\Payouts\PayoutQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPayoutsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'nullable', Rule::in(PayoutRequestStatus::values())],
            'filter.provider_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filter.from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'sort' => ['sometimes', 'nullable', Rule::in(PayoutQueries::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
