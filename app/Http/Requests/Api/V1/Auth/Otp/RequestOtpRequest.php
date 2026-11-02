<?php

namespace App\Http\Requests\Api\V1\Auth\Otp;

use App\Enums\OtpPurpose;
use App\Http\Requests\Concerns\AuthInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestOtpRequest extends FormRequest
{
    use AuthInput;

    /**
     * `change_phone` codes are requested through the authenticated `change-phone/otp` endpoint.
     */
    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
            'purpose' => ['required', Rule::in([OtpPurpose::Login->value, OtpPurpose::Register->value])],
            'app' => $this->appRules(),
        ];
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from($this->validated('purpose'));
    }
}
