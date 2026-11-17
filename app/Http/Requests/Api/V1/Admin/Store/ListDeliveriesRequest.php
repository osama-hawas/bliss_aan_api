<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her assigned internal deliveries.
 */
class ListDeliveriesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', 'in:in_transit,delivered,failed'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
