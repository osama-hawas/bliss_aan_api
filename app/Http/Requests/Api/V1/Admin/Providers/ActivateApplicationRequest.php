<?php

namespace App\Http\Requests\Api\V1\Admin\Providers;

use Illuminate\Foundation\Http\FormRequest;

class ActivateApplicationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
