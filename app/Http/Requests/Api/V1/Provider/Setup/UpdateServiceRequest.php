<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `custom_price: null` resets to the guide price. The guide price is neither a minimum nor a maximum.
 */
class UpdateServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'custom_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'at_provider_location' => ['sometimes', 'boolean'],
            'at_event_location' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
