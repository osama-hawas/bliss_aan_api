<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff order list (§8.2).
 */
class ListStoreOrdersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:64'],
            'filter.status' => ['nullable', 'in:all,pending_payment,confirmed,preparing,shipped,delivered,cancelled,expired'],
            'filter.method' => ['nullable', 'in:carrier,internal'],
            'filter.shipment_status' => ['nullable', 'in:pending,in_transit,delivered,failed'], // S21 review L5: latest shipment
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'in:created_at,-created_at,total,-total'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
