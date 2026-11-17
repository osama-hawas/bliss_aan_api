<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Enums\UserStatus;
use App\Services\Staff\StaffManagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStaffRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.role' => ['nullable', 'string', 'max:40'],
            'filter.status' => ['nullable', Rule::in([UserStatus::Active->value, UserStatus::Suspended->value])],
            'sort' => ['nullable', Rule::in(StaffManagement::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, role: ?string, status: ?string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'role' => $this->validated('filter.role'),
            'status' => $this->validated('filter.status'),
        ];
    }
}
