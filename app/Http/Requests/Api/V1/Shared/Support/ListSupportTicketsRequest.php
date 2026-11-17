<?php

namespace App\Http\Requests\Api\V1\Shared\Support;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSupportTicketsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(TicketStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function status(): ?TicketStatus
    {
        return TicketStatus::tryFrom((string) $this->validated('filter.status'));
    }
}
