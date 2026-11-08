<?php

namespace App\Http\Requests\Api\V1\Admin\Providers;

use App\Enums\ProviderAccountType;
use App\Enums\ProviderStatus;
use App\Services\Providers\ProviderReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListApplicationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(ProviderStatus::class)],
            'filter.account_type' => ['nullable', Rule::enum(ProviderAccountType::class)],
            'filter.city_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(ProviderReview::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string, account_type: ?string, city_id: ?int}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'account_type' => $this->validated('filter.account_type'),
            'city_id' => $this->filled('filter.city_id') ? (int) $this->validated('filter.city_id') : null,
        ];
    }
}
