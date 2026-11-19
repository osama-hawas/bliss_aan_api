<?php

namespace App\Http\Requests\Api\V1\Shared\Account;

use App\Enums\UserType;
use App\Services\Account\ProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * D152: a client edits name, email, city, birth date and language; a provider only email, birth date and language
 * (a locked field sent by a provider → 422 on that field). Birth date: optional, at least 18 years ago.
 */
class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        $adultBy = now('Asia/Riyadh')->subYears(18)->toDateString();
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]+$/', 'max:255'],
            'city_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$adultBy],
            'locale' => ['sometimes', 'required', Rule::in(['ar', 'en'])],
        ];

        $editable = ProfileService::editableFields($this->user()?->type ?? UserType::Client);
        foreach (array_keys($rules) as $field) {
            if (! in_array($field, $editable, true)) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => __('account.birth_date_adult'),
            '*.prohibited' => __('account.field_locked'),
        ];
    }

    public function attributes(): array
    {
        return __('account.attributes');
    }
}
