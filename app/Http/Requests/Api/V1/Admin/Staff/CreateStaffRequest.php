<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Rules\SaudiMobile;
use App\Services\Staff\StaffAccess;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * D170: name + phone + optional email + one role. A phone used by any account (soft-deleted included) is refused.
 */
class CreateStaffRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', new SaudiMobile, Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', StaffAccess::GUARD)],
        ];
    }
}
