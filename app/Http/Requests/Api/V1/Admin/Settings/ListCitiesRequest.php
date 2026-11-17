<?php

namespace App\Http\Requests\Api\V1\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCitiesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::in(['active', 'stopped'])],
        ];
    }
}
