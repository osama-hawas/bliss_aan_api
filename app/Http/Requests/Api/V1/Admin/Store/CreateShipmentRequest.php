<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ship an order (§8.2). `Idempotency-Key` header → the shipment request key.
 */
class CreateShipmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'method' => ['required', 'in:carrier,internal'],
            'assigned_admin_id' => ['nullable', 'integer', 'min:1', 'required_if:method,internal'],
        ];
    }
}
