<?php

namespace App\Http\Requests\Api\V1\Client\Payments;

use App\Enums\PaymentMethodType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `Idempotency-Key` header (uuid) + one saved method of hers **or** a fresh gateway token (slice-10-contract.md §10.1).
 * Apple Pay needs a token; `save_method` only with a fresh card token. Card data is refused (D188).
 */
class PayOfferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        $card = $this->input('method') === PaymentMethodType::Card->value;

        return [
            'idempotency_key' => ['required', 'uuid'],
            'method' => ['required', Rule::enum(PaymentMethodType::class)],
            'payment_method_id' => [$card ? 'required_without:token' : 'prohibited', 'nullable', 'integer', 'prohibits:token'],
            'token' => [$card ? 'required_without:payment_method_id' : 'required', 'nullable', 'string', 'max:255'],
            'save_method' => [$card && $this->filled('token') ? 'nullable' : 'prohibited', 'boolean'],
            'number' => ['prohibited'],
            'card_number' => ['prohibited'],
            'cvv' => ['prohibited'],
        ];
    }
}
