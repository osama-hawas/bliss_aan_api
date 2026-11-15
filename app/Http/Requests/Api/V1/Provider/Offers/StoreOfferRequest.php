<?php

namespace App\Http\Requests\Api\V1\Provider\Offers;

use App\Services\Offers\OfferSender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A detailed offer (slice-5b-contract.md §3.2). The `Idempotency-Key` header is validated as `idempotency_key`.
 * Money arrives as a number or string with at most 2 decimals; the total rule is checked by OfferSender.
 */
class StoreOfferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*' => ['required', 'array:description,quantity,unit_price'],
            'items.*.description' => ['required', 'string', 'max:150'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'validity_days' => ['required', 'integer', Rule::in(OfferSender::VALIDITY_DAYS)],
            'notes' => ['nullable', 'string', 'max:1000'],
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
