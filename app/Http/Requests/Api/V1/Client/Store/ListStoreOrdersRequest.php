<?php

namespace App\Http\Requests\Api\V1\Client\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her order list (§6.4).
 */
class ListStoreOrdersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', 'in:active,done'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
