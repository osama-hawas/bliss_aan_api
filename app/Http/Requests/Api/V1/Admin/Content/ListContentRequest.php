<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\Audience;
use App\Enums\ContentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared list filters of the content screens (Slice 20). Subclasses give the allowed sorts and statuses.
 */
abstract class ListContentRequest extends FormRequest
{
    /**
     * @return list<string>
     */
    abstract protected function sorts(): array;

    /**
     * @return list<string>
     */
    protected function statuses(): array
    {
        return array_map(fn (ContentStatus $s) => $s->value, ContentStatus::cases());
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::in($this->statuses())],
            'filter.audience' => ['nullable', Rule::enum(Audience::class)],
            'filter.city_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in($this->sorts())],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string, audience: ?string, city_id: ?int}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'audience' => $this->validated('filter.audience'),
            'city_id' => $this->filled('filter.city_id') ? (int) $this->validated('filter.city_id') : null,
        ];
    }
}
