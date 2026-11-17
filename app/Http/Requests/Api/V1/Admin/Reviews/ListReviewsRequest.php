<?php

namespace App\Http\Requests\Api\V1\Admin\Reviews;

use App\Enums\ReviewStatus;
use App\Services\Reviews\ReviewModerationQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dashboard review list filters (slice-17-contract.md §8).
 */
class ListReviewsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'filter.rating' => ['nullable', 'integer', 'between:1,5'],
            'filter.provider_id' => ['nullable', 'integer'],
            'filter.reported' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(ReviewModerationQueries::REVIEW_SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'rating' => $this->filled('filter.rating') ? (int) $this->validated('filter.rating') : null,
            'provider_id' => $this->filled('filter.provider_id') ? (int) $this->validated('filter.provider_id') : null,
            'reported' => $this->filled('filter.reported') ? $this->boolean('filter.reported') : null,
        ];
    }
}
