<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Services\Staff\StaffAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Role change and its impact preview; the change itself also needs a reason.
 */
class StaffRoleRequest extends FormRequest
{
    public function rules(): array
    {
        $reason = $this->is('*/role-impact') ? ['nullable'] : ['required', 'string', 'min:3', 'max:500'];

        return [
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', StaffAccess::GUARD)],
            'reason' => $reason,
        ];
    }
}
