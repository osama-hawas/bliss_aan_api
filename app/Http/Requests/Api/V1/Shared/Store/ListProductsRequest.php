<?php

namespace App\Http\Requests\Api\V1\Shared\Store;

use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.category_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function categoryId(): ?int
    {
        return $this->filled('filter.category_id') ? (int) $this->validated('filter.category_id') : null;
    }

    public function q(): ?string
    {
        return $this->filled('q') ? trim($this->validated('q')) : null;
    }
}
