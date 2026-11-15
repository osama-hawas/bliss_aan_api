<?php

namespace App\Http\Requests\Api\V1\Client\Bookings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Empty body; the `Idempotency-Key` header is required (D112) and validated as `idempotency_key`.
 */
class AcceptOfferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'uuid']];
    }
}
