<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Services\Finance\LedgerEntryQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLedgerEntriesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:30'],
            'filter.provider_id' => ['nullable', 'integer', 'min:1'],
            'filter.type' => ['nullable', Rule::in(LedgerEntryQueries::TYPES)],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(LedgerEntryQueries::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, provider_id: ?int, type: ?string, from: ?string, to: ?string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'provider_id' => $this->filled('filter.provider_id') ? (int) $this->validated('filter.provider_id') : null,
            'type' => $this->validated('filter.type'),
            'from' => $this->validated('filter.from'),
            'to' => $this->validated('filter.to'),
        ];
    }
}
