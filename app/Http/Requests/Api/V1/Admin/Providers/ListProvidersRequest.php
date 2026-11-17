<?php

namespace App\Http\Requests\Api\V1\Admin\Providers;

use App\Enums\ProviderAccountType;
use App\Services\Providers\ProviderRoster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProvidersRequest extends FormRequest
{
    /**
     * Old dashboard links sort by `name`: read it as `display_name` instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        if (in_array($this->query('sort'), ['name', '-name'], true)) {
            $this->merge(['sort' => str_replace('name', 'display_name', (string) $this->query('sort'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', Rule::in(['active', 'suspended'])],
            'filter.account_type' => ['nullable', Rule::enum(ProviderAccountType::class)],
            'filter.city_id' => ['nullable', 'integer'],
            'filter.is_verified' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'filter.is_featured' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'sort' => ['nullable', Rule::in(ProviderRoster::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{q: ?string, status: ?string, account_type: ?string, city_id: ?int, is_verified: ?bool, is_featured: ?bool}
     */
    public function filters(): array
    {
        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->validated('filter.status'),
            'account_type' => $this->validated('filter.account_type'),
            'city_id' => $this->filled('filter.city_id') ? (int) $this->validated('filter.city_id') : null,
            'is_verified' => $this->flag('filter.is_verified'),
            'is_featured' => $this->flag('filter.is_featured'),
        ];
    }

    private function flag(string $key): ?bool
    {
        $value = $this->validated($key);

        return $value === null ? null : in_array($value, ['true', '1'], true);
    }
}
