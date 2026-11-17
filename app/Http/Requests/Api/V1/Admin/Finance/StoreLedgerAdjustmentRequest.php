<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Enums\LedgerAdjustmentDirection;
use App\Enums\LedgerAdjustmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new adjustment (slice-19-contract.md §4.1). The `Idempotency-Key` header is validated as `idempotency_key`; the
 * provider / booking / payout links and the limits are checked by LedgerAdjustments under the provider row lock.
 */
class StoreLedgerAdjustmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'provider_id' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::enum(LedgerAdjustmentDirection::class)],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0{1,2})?$/'],
            'reason_code' => ['required', Rule::enum(LedgerAdjustmentReason::class)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'booking_id' => ['nullable', 'integer', 'min:1'],
            'payout_request_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $data = $this->validated();
        unset($data['idempotency_key']);

        return $data;
    }
}
