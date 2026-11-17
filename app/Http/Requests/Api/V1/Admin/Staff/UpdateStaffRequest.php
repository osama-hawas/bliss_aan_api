<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Name and email only; the phone is not editable from the dashboard (D170).
 */
class UpdateStaffRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required_without:email', 'sometimes', 'string', 'min:2', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }

    /**
     * `email: null` clears the email, so at least one of the two keys must be sent.
     */
    public function after(): array
    {
        return [function ($validator) {
            if (! $this->has('name') && ! $this->has('email')) {
                $validator->errors()->add('name', __('validation.required', ['attribute' => 'name']));
            }
        }];
    }
}
