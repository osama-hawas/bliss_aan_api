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
     * Plain messages (QA 2026-11-23): the key rules were reported back as «صيغة الاسم غير صالحة» under a field the
     * form calls «المعرّف».
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $m = fn (string $key) => __("errors.role_form.{$key}");

        return [
            'name.required' => $m('key_required'),
            'name.string' => $m('key_format'),
            'name.regex' => $m('key_format'),
            'name.unique' => $m('key_taken'),
            'label.required' => $m('label_required'),
            'label.ar.required' => $m('label_ar_required'),
            'label.ar.min' => $m('label_length'),
            'label.ar.max' => $m('label_length'),
            'label.en.required' => $m('label_en_required'),
            'label.en.min' => $m('label_length'),
            'label.en.max' => $m('label_length'),
            'reason.required' => $m('reason_required'),
            'reason.min' => $m('reason_length'),
            'reason.max' => $m('reason_length'),
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
