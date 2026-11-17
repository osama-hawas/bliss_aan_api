<?php

namespace App\Http\Requests\Api\V1\Admin\Reviews;

use App\Enums\ContentReportStatus;
use App\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dashboard content report filters (D262). Targets are reviews only.
 */
class ListContentReportsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(ContentReportStatus::class)],
            'filter.reason' => ['nullable', Rule::enum(ReportReason::class)],
            'filter.target_type' => ['nullable', 'in:review'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
