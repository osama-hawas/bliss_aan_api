<?php

namespace App\Http\Requests\Api\V1\Auth\Phone;

use App\Http\Requests\Concerns\AuthInput;
use Illuminate\Foundation\Http\FormRequest;

class ChangePhoneRequest extends FormRequest
{
    use AuthInput;

    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
            'code' => $this->codeRules(),
        ];
    }
}
