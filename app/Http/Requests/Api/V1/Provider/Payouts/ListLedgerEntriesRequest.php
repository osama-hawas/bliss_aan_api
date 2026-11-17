<?php

namespace App\Http\Requests\Api\V1\Provider\Payouts;

use App\Enums\LedgerEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLedgerEntriesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.type' => ['sometimes', 'nullable', Rule::in(LedgerEntryType::values())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
