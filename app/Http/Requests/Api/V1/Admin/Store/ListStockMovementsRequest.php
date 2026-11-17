<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

class ListStockMovementsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.variant_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
