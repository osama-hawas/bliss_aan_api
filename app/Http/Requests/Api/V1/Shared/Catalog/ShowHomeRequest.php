<?php

namespace App\Http\Requests\Api\V1\Shared\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowHomeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')->where('is_active', true)]];
    }

    public function cityId(): ?int
    {
        return $this->filled('city_id') ? (int) $this->validated('city_id') : null;
    }
}
