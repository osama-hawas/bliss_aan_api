<?php

namespace App\Http\Requests\Api\V1\Client\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

class OpenRequestEligibilityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer'],
            'city_id' => ['required', 'integer'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer', 'distinct'],
        ];
    }
}
