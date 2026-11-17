<?php

namespace App\Http\Requests\Api\V1\Admin\Payments;

use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRefundsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(RefundStatus::class)],
            'filter.kind' => ['nullable', Rule::enum(RefundKind::class)],
            'sort' => ['nullable', 'in:created_at,-created_at,amount,-amount'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
