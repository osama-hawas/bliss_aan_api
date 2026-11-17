<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductCategoriesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
        ];
    }
}
