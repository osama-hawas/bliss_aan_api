<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Services\Finance\ProviderBalances;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProviderBalancesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.balance' => ['nullable', 'in:negative,positive,zero'],
            'sort' => ['nullable', Rule::in(ProviderBalances::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, balance: ?string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'balance' => $this->validated('filter.balance'),
        ];
    }
}
