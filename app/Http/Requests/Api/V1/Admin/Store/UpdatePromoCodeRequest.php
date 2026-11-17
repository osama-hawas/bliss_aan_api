<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a promo code (§8.1); the code itself never changes.
 */
class UpdatePromoCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:percent,fixed'],
            'value' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'min_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ];
    }
}
