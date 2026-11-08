<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her display name was checked at review; changing it goes through support.
 */
class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bio' => ['sometimes', 'nullable', 'string', 'max:400'],
            'accepts_open_requests' => ['sometimes', 'boolean'],
        ];
    }
}
