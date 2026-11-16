<?php

namespace App\Http\Requests\Api\V1\Shared\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class ListNotificationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.read' => ['nullable', 'in:true,false,1,0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function readFilter(): ?bool
    {
        $value = $this->validated('filter.read');

        return $value === null ? null : in_array($value, ['true', '1'], true);
    }
}
