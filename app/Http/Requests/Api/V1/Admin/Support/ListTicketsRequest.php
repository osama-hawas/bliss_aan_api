<?php

namespace App\Http\Requests\Api\V1\Admin\Support;

use App\Enums\TicketRelatedType;
use App\Enums\TicketStatus;
use App\Enums\TicketTopic;
use App\Services\Support\StaffTicketQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dashboard ticket queue filters (slice-17-contract.md §6). `filter[assigned]` = me, none, any or a staff id.
 */
class ListTicketsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(TicketStatus::class)],
            'filter.topic' => ['nullable', Rule::enum(TicketTopic::class)],
            'filter.assigned' => ['nullable', 'regex:/^(me|none|any|[0-9]+)$/'],
            'filter.user_type' => ['nullable', 'in:client,provider'],
            'filter.related_type' => ['nullable', Rule::enum(TicketRelatedType::class)],
            'sort' => ['nullable', Rule::in(StaffTicketQueries::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $statuses = $this->validated('filter.status');

        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $statuses ? array_values($statuses) : null,
            'topic' => $this->validated('filter.topic'),
            'assigned' => $this->validated('filter.assigned'),
            'user_type' => $this->validated('filter.user_type'),
            'related_type' => $this->validated('filter.related_type'),
        ];
    }
}
