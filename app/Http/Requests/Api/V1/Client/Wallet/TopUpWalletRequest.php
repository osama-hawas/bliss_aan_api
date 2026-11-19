<?php

namespace App\Http\Requests\Api\V1\Client\Wallet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Any amount above zero (owner decision 2026-11-19), two decimals at most; the upper bound is only technical (column
 * size). `Idempotency-Key` header: a retried tap never adds twice.
 */
class TopUpWalletRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000000'],
        ];
    }
}
