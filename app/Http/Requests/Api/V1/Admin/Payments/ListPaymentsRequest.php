<?php

namespace App\Http\Requests\Api\V1\Admin\Payments;

use App\Enums\PayableType;
use App\Enums\PaymentRefundState;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPaymentsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'filter.payable_type' => ['nullable', Rule::enum(PayableType::class)],
            'filter.refund' => ['nullable', Rule::in([PaymentRefundState::None->value, PaymentRefundState::Partial->value, PaymentRefundState::Full->value])],
            'sort' => ['nullable', 'in:created_at,-created_at,amount,-amount'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
