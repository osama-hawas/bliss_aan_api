<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use App\Services\Store\Admin\ProductManagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.category_id' => ['nullable', 'integer'],
            'filter.status' => ['nullable', Rule::in(['active', 'inactive'])],
            'filter.stock' => ['nullable', Rule::in(['in_stock', 'out_of_stock'])],
            'filter.low_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(ProductManagement::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, category_id: ?int, status: ?string, stock: ?string, low_stock: bool}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'category_id' => $this->filled('filter.category_id') ? (int) $this->validated('filter.category_id') : null,
            'status' => $this->validated('filter.status'),
            'stock' => $this->validated('filter.stock'),
            'low_stock' => $this->boolean('filter.low_stock'),
        ];
    }
}
