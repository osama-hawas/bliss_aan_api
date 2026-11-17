<?php

namespace App\Http\Requests\Api\V1\Admin\Roles;

use App\Services\Staff\StaffAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A custom role (D168): immutable key, AR/EN label, permission set.
 */
class CreateRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,39}$/', Rule::unique('roles', 'name')->where('guard_name', StaffAccess::GUARD)],
            'label' => ['required', 'array:ar,en'],
            'label.ar' => ['required', 'string', 'min:2', 'max:60'],
            'label.en' => ['required', 'string', 'min:2', 'max:60'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', StaffAccess::GUARD)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_unique($this->validated('permissions')));
    }
}
