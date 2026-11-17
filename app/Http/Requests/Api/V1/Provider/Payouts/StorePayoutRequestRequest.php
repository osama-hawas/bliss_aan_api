<?php

namespace App\Http\Requests\Api\V1\Provider\Payouts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `{amount: "1234.50"}` + header `Idempotency-Key` (uuid). Minimum and balance are checked by the service (D221).
 */
class StorePayoutRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0{1,2})?$/'],
        ];
    }
}
