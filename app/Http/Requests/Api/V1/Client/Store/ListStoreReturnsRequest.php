<?php

namespace App\Http\Requests\Api\V1\Client\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her returns (S23, §5.2): `filter[status]=open|done`.
 */
class ListStoreReturnsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', 'in:open,done'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
