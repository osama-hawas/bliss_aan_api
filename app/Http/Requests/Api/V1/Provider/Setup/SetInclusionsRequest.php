<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

class SetInclusionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'disabled_inclusion_ids' => ['present', 'array', 'max:100'],
            'disabled_inclusion_ids.*' => ['integer', 'distinct'],
        ];
    }
}
