<?php

namespace App\Http\Requests\Api\V1\Shared\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class ListServicesRequest extends FormRequest
{
    public function rules(): array
    {
        return ['filter.category_id' => ['nullable', 'integer']];
    }

    public function categoryId(): ?int
    {
        return $this->filled('filter.category_id') ? (int) $this->validated('filter.category_id') : null;
    }
}
