<?php

namespace App\Http\Requests\Api\V1\Provider\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A predefined reason (required) and an explanation. The explanation is required for a plain decline (D51) and
 * optional when she withdraws her offer and declines (owner decision 2026-11-19, changes D91); the service checks which.
 */
class DeclineServiceRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decline_reason_id' => ['required', 'integer', Rule::exists('decline_reasons', 'id')->where('is_active', true)],
            'explanation' => ['nullable', 'string', 'max:500'],
        ];
    }
}
