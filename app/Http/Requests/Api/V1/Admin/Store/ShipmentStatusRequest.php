<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delivered / failed (§8.2). A failure needs a reason.
 */
class ShipmentStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:delivered,failed'],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'min:3', 'max:500', 'required_if:status,failed'],
        ];
    }
}
