<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

class SetAddonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'is_active' => ['required', 'boolean'],
            'provider_service_ids' => ['present', 'array', 'max:50'],
            'provider_service_ids.*' => ['integer', 'distinct'],
        ];
    }
}
