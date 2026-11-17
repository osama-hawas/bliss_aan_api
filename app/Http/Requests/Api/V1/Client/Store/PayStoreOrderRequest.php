<?php

namespace App\Http\Requests\Api\V1\Client\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pays her order through Slice 10 (§6.3); same body as `client/offers/{id}/payments`.
 */
class PayStoreOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'method' => ['required', 'in:card,apple_pay'],
            'payment_method_id' => ['nullable', 'integer', 'min:1', 'required_without:token'],
            'token' => ['nullable', 'string', 'max:255', 'required_without:payment_method_id', 'required_if:method,apple_pay'],
            'save_method' => ['sometimes', 'boolean', 'prohibited_unless:method,card'],
        ];
    }

    /**
     * Exactly one of `payment_method_id` / `token` (Slice 10 §10.1).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('payment_method_id') && $this->filled('token')) {
                $validator->errors()->add('token', __('validation.prohibits', ['attribute' => 'payment method id', 'other' => 'token']));
            }
        });
    }
}
