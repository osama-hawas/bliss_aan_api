<?php

namespace App\Http\Requests\Api\V1\Admin\Reports;

use App\Services\Audit\AuditLogQueries;
use Illuminate\Validation\Rule;

/**
 * Export filters (D322): the scope filters, `filter[status]`, and for the action log its own filters and `q`.
 */
class ExportReportRequest extends ReportScopeRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filter.status' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'q' => ['nullable', 'string', 'max:100'],
            'filter.action' => ['nullable', 'string', 'max:100'],
            'filter.actor_id' => ['nullable', 'integer'],
            'filter.subject_type' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'sort' => ['nullable', Rule::in(AuditLogQueries::SORTS)],
        ];
    }

    /**
     * @param  list<string>  $extra  dataset-specific keys (ReportDataset::extraFilters)
     * @return array<string, mixed>
     */
    public function filters(array $extra): array
    {
        $filters = ['status' => $this->validated('filter.status')];
        foreach ($extra as $key) {
            $filters[$key] = $key === 'q'
                ? ($this->filled('q') ? trim($this->validated('q')) : null)
                : $this->validated("filter.{$key}");
        }
        if (isset($filters['actor_id'])) {
            $filters['actor_id'] = (int) $filters['actor_id'];
        }

        return $filters;
    }
}
