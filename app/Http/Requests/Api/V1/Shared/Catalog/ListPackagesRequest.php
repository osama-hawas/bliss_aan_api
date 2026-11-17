<?php

namespace App\Http\Requests\Api\V1\Shared\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class ListPackagesRequest extends FormRequest
{
    public function rules(): array
    {
        return ['filter.event_type_id' => ['nullable', 'integer']];
    }

    public function eventTypeId(): ?int
    {
        return $this->filled('filter.event_type_id') ? (int) $this->validated('filter.event_type_id') : null;
    }
}
