<?php

namespace App\Http\Requests\Api\V1\Shared\Providers;

use App\Services\Providers\ProviderDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProvidersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.city_id' => ['nullable', 'integer'],
            'filter.category_id' => ['nullable', 'integer'],
            'filter.service_id' => ['nullable', 'integer'],
            'filter.min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'filter.verified' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(ProviderDirectory::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, city_id: ?int, category_id: ?int, service_id: ?int, min_rating: ?float, verified: bool}
     */
    public function filters(): array
    {
        $int = fn (string $key) => $this->filled("filter.{$key}") ? (int) $this->validated("filter.{$key}") : null;

        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'city_id' => $int('city_id'),
            'category_id' => $int('category_id'),
            'service_id' => $int('service_id'),
            'min_rating' => $this->filled('filter.min_rating') ? (float) $this->validated('filter.min_rating') : null,
            'verified' => $this->boolean('filter.verified'),
        ];
    }
}
