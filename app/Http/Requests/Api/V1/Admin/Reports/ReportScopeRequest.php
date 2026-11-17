<?php

namespace App\Http\Requests\Api\V1\Admin\Reports;

use App\Services\Reports\ReportScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Period + city filters shared by indicators, drill-down and exports (D316): `filter[from]`, `filter[to]` (Riyadh
 * dates, inclusive) and `filter[city_id]`.
 */
class ReportScopeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'filter.city_id' => ['nullable', 'integer', 'min:1'], // an unknown city simply counts nothing
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function scope(): ReportScope
    {
        return ReportScope::fromFilters([
            'from' => $this->validated('filter.from'),
            'to' => $this->validated('filter.to'),
            'city_id' => $this->validated('filter.city_id'),
        ]);
    }
}
