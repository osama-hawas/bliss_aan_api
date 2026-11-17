<?php

namespace App\Http\Requests\Api\V1\Admin\Clients;

use App\Enums\DeletionRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDeletionRequestsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(DeletionRequestStatus::class)],
            'filter.user_type' => ['nullable', Rule::in(['client', 'provider'])],
            'filter.blocked' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string, user_type: ?string, blocked: ?bool}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'user_type' => $this->validated('filter.user_type'),
            'blocked' => $this->filled('filter.blocked') ? filter_var($this->validated('filter.blocked'), FILTER_VALIDATE_BOOLEAN) : null,
        ];
    }
}
