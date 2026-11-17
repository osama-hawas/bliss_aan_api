<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use App\Enums\StoreReturnReason;
use App\Enums\StoreReturnStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff return list (S23, §6.1).
 */
class ListStoreReturnsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:64'],
            'filter.status' => ['nullable', Rule::in(['open', ...StoreReturnStatus::values()])],
            'filter.reason' => ['nullable', Rule::enum(StoreReturnReason::class)],
            'filter.store_order_id' => ['nullable', 'integer', 'min:1'],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'in:created_at,-created_at,refund_amount,-refund_amount'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
