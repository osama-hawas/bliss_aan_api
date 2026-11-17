<?php

namespace App\Http\Requests\Api\V1\Admin\Reports;

use App\Services\Audit\AuditLogQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditLogsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.action' => ['nullable', 'string', 'max:100'],
            'filter.actor_id' => ['nullable', 'integer'],
            'filter.subject_type' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'sort' => ['nullable', Rule::in(AuditLogQueries::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, action: ?string, actor_id: ?int, subject_type: ?string, from: ?string, to: ?string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'action' => $this->validated('filter.action'),
            'actor_id' => $this->filled('filter.actor_id') ? (int) $this->validated('filter.actor_id') : null,
            'subject_type' => $this->validated('filter.subject_type'),
            'from' => $this->validated('filter.from'),
            'to' => $this->validated('filter.to'),
        ];
    }
}
