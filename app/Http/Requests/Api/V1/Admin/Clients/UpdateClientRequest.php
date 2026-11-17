<?php

namespace App\Http\Requests\Api\V1\Admin\Clients;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * D174: name, email and city only, with a reason. The phone is never editable by staff.
 */
class UpdateClientRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{name: string, email: ?string, city_id: ?int}
     */
    public function profile(): array
    {
        return [
            'name' => trim($this->validated('name')),
            'email' => $this->filled('email') ? trim($this->validated('email')) : null,
            'city_id' => $this->filled('city_id') ? (int) $this->validated('city_id') : null,
        ];
    }
}
