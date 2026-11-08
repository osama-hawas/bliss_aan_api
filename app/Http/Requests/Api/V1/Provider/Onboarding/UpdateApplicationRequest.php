<?php

namespace App\Http\Requests\Api\V1\Provider\Onboarding;

use App\Enums\ProviderAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of the application (only the fields sent change). Limits match the join wizard.
 * Completeness (establishment details, services, cities, required documents) is checked on submit.
 */
class UpdateApplicationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'account_type' => ['sometimes', Rule::enum(ProviderAccountType::class)],
            'display_name' => ['sometimes', 'string', 'max:60'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'representative_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'representative_title' => ['sometimes', 'nullable', 'string', 'max:60'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:400'],
            'city_id' => ['sometimes', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'started_year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'service_ids' => ['sometimes', 'array', 'max:50'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')->where('is_active', true)],
            'coverage_city_ids' => ['sometimes', 'array', 'max:20'],
            'coverage_city_ids.*' => ['integer', 'distinct', Rule::exists('cities', 'id')->where('is_active', true)],
        ];
    }

    /**
     * Trimmed; empty strings become null (API rule: empty values are `null`).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(collect($this->only([
            'display_name', 'legal_name', 'representative_name', 'representative_title', 'email', 'bio',
        ]))->map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v))->all());
    }
}
