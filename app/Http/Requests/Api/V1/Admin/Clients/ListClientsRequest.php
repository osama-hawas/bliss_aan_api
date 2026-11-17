<?php

namespace App\Http\Requests\Api\V1\Admin\Clients;

use App\Enums\UserStatus;
use App\Services\Accounts\ClientAccounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListClientsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::enum(UserStatus::class)],
            'filter.city_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(ClientAccounts::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string, city_id: ?int}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'city_id' => $this->filled('filter.city_id') ? (int) $this->validated('filter.city_id') : null,
        ];
    }
}
