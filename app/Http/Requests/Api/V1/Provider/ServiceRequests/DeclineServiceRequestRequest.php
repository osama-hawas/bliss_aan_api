<?php

namespace App\Http\Requests\Api\V1\Provider\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A predefined reason and a written explanation, both required (D51).
 */
class DeclineServiceRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decline_reason_id' => ['required', 'integer', Rule::exists('decline_reasons', 'id')->where('is_active', true)],
            'explanation' => ['required', 'string', 'max:500'],
        ];
    }
}
