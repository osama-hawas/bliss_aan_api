<?php

namespace App\Http\Requests\Api\V1\Admin\Cancellations;

use App\Enums\CancellationRequesterRole;
use App\Enums\CancellationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCancellationRequestsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(CancellationStatus::class)],
            'filter.requester_role' => ['nullable', Rule::enum(CancellationRequesterRole::class)],
            'sort' => ['nullable', 'in:created_at,-created_at'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
