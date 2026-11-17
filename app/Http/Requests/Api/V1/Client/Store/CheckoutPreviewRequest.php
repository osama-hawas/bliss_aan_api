<?php

namespace App\Http\Requests\Api\V1\Client\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout preview (slice-9b-contract.md §6.1).
 */
class CheckoutPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'integer', 'min:1'],
            'promo_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
