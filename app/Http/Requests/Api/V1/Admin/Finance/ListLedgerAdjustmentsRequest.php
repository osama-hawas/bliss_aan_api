<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Enums\LedgerAdjustmentDirection;
use App\Enums\LedgerAdjustmentReason;
use App\Enums\LedgerAdjustmentStatus;
use App\Services\Finance\AdjustmentQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLedgerAdjustmentsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(LedgerAdjustmentStatus::class)],
            'filter.direction' => ['nullable', Rule::enum(LedgerAdjustmentDirection::class)],
            'filter.reason_code' => ['nullable', Rule::enum(LedgerAdjustmentReason::class)],
            'filter.provider_id' => ['nullable', 'integer', 'min:1'],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(AdjustmentQueries::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string, direction: ?string, reason_code: ?string, provider_id: ?int, from: ?string, to: ?string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'direction' => $this->validated('filter.direction'),
            'reason_code' => $this->validated('filter.reason_code'),
            'provider_id' => $this->filled('filter.provider_id') ? (int) $this->validated('filter.provider_id') : null,
            'from' => $this->validated('filter.from'),
            'to' => $this->validated('filter.to'),
        ];
    }
}
