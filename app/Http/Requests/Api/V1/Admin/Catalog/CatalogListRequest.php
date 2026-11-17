<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use App\Enums\CatalogStatus;
use App\Services\Catalog\Admin\CatalogRows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lists of the admin catalog: `q`, `filter[status]`, `filter[category_id]` / `filter[guide_price]` (services),
 * `filter[service_id]` (add-ons), `sort`, `per_page`.
 */
class CatalogListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(CatalogStatus::class)],
            'filter.category_id' => ['nullable', 'integer'],
            'filter.service_id' => ['nullable', 'integer'],
            'filter.guide_price' => ['nullable', Rule::in(['set', 'not_set'])],
            'sort' => ['nullable', Rule::in(CatalogRows::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string, category_id: ?int, service_id: ?int, guide_price: ?string}
     */
    public function filters(): array
    {
        $int = fn (string $key) => $this->filled($key) ? (int) $this->validated($key) : null;

        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'category_id' => $int('filter.category_id'),
            'service_id' => $int('filter.service_id'),
            'guide_price' => $this->validated('filter.guide_price'),
        ];
    }
}
