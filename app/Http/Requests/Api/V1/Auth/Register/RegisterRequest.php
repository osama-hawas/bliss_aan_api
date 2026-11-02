<?php

namespace App\Http\Requests\Api\V1\Auth\Register;

use App\Http\Requests\Concerns\AuthInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Req 01: name and terms are required; email and city are optional.
 */
class RegisterRequest extends FormRequest
{
    use AuthInput;

    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'terms_accepted' => ['accepted'],
            'app' => $this->appRules(),
            ...$this->deviceRules(),
        ];
    }

    /**
     * @return array{name: string, email: ?string, city_id: ?int}
     */
    public function profile(): array
    {
        return [
            'name' => $this->validated('name'),
            'email' => $this->validated('email'),
            'city_id' => $this->validated('city_id'),
        ];
    }
}
