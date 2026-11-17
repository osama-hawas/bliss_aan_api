<?php

namespace App\Http\Requests\Api\V1\Client\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the gateway token (D188): card numbers, CVV or expiry fields are refused.
 */
class StorePaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'number' => ['prohibited'],
            'card_number' => ['prohibited'],
            'cvv' => ['prohibited'],
        ];
    }
}
