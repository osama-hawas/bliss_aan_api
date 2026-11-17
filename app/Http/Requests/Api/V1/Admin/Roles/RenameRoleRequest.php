<?php

namespace App\Http\Requests\Api\V1\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;

class RenameRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['required', 'array:ar,en'],
            'label.ar' => ['required', 'string', 'min:2', 'max:60'],
            'label.en' => ['required', 'string', 'min:2', 'max:60'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
