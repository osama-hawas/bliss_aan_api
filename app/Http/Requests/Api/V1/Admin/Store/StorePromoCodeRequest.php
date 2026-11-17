<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * New promo code (§8.1).
 */
class StorePromoCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'min_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ];
    }
}
