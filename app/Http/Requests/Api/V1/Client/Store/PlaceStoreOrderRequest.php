<?php

namespace App\Http\Requests\Api\V1\Client\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout: creates the unpaid order snapshot (§6.2). `Idempotency-Key` header is validated as `idempotency_key`.
 */
class PlaceStoreOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'address_id' => ['required', 'integer', 'min:1'],
            'promo_code' => ['nullable', 'string', 'max:32'],
            'expected_total' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
        ];
    }
}
