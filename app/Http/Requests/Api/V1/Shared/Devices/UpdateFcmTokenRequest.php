<?php

namespace App\Http\Requests\Api\V1\Shared\Devices;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFcmTokenRequest extends FormRequest
{
    /**
     * `null` clears the token (e.g. the user turned off push in the OS).
     */
    public function rules(): array
    {
        return ['fcm_token' => ['present', 'nullable', 'string', 'max:512']];
    }
}
