<?php

namespace App\Http\Requests\Api\V1\Shared;

use App\Enums\BookingStatus;
use App\Services\Bookings\BookingQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Client and provider booking lists: `filter[status][]`, `sort`, `page`, `per_page`.
 */
class ListBookingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(BookingStatus::class)],
            'sort' => ['nullable', Rule::in(BookingQueries::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return array_values($this->validated('filter.status') ?? []);
    }
}
