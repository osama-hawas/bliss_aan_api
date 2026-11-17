<?php

namespace App\Http\Requests\Api\V1\Admin\Roles;

use App\Services\Staff\StaffAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A role's full permission set: the save (with a reason) and its impact preview (without).
 */
class RolePermissionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', StaffAccess::GUARD)],
            'reason' => $this->isMethod('put') ? ['required', 'string', 'min:3', 'max:500'] : ['nullable'],
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
