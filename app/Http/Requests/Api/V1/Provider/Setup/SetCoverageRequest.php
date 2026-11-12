<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The whole list of cities she covers (D69). Same city rules as the application; at least one, since an
 * application cannot be submitted without coverage either.
 */
class SetCoverageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'coverage_city_ids' => ['required', 'array', 'min:1', 'max:20'],
            'coverage_city_ids.*' => ['integer', 'distinct', Rule::exists('cities', 'id')->where('is_active', true)],
        ];
    }
}
