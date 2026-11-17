<?php

namespace App\Http\Requests\Api\V1\Provider\Payouts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `{amount: "1234.50"}` + header `Idempotency-Key` (uuid). Minimum and balance are checked by the service (D221).
 * A JSON number (`1234.5`) is accepted too and read as its shortest decimal text; more than 2 decimals → 422.
 */
class StorePayoutRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
            // Numbers are normalised to text before the money regex; floats keep their shortest form (100.5 → "100.5").
            'amount' => is_int($amount) || is_float($amount) ? json_encode($amount) : $amount,
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0{1,2})?$/'],
        ];
    }
}
