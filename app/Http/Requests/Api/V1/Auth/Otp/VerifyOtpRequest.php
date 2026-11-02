<?php

namespace App\Http\Requests\Api\V1\Auth\Otp;

use App\Enums\OtpPurpose;
use App\Http\Requests\Concerns\AuthInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyOtpRequest extends FormRequest
{
    use AuthInput;

    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
            'purpose' => ['required', Rule::in([OtpPurpose::Login->value, OtpPurpose::Register->value])],
            'code' => $this->codeRules(),
            'app' => $this->appRules(),
            ...$this->deviceRules(),
        ];
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from($this->validated('purpose'));
    }
}
