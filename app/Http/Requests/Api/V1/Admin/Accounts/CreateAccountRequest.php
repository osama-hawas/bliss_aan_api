<?php

namespace App\Http\Requests\Api\V1\Admin\Accounts;

use App\Enums\ProviderAccountType;
use App\Rules\SaudiMobile;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A client or provider account added from the dashboard: name + Saudi mobile + optional email and city.
 * A phone used by any account (soft-deleted included) is refused, like self sign-up. Providers may also pick the
 * account type of their application. She signs in with OTP on that phone; there is no password.
 */
class CreateAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $phone = Phone::normalizeSaudiMobile(is_string($this->input('phone')) ? $this->input('phone') : null);
        if ($phone !== null) {
            $this->merge(['phone' => $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'phone' => ['required', 'string', new SaudiMobile, Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:150'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'account_type' => ['nullable', Rule::enum(ProviderAccountType::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{name: string, phone: string, email: ?string, city_id: ?int}
     */
    public function profile(): array
    {
        return [
            'name' => trim($this->validated('name')),
            'phone' => $this->validated('phone'),
            'email' => $this->filled('email') ? trim($this->validated('email')) : null,
            'city_id' => $this->filled('city_id') ? (int) $this->validated('city_id') : null,
        ];
    }
}
