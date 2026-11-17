<?php

namespace App\Http\Requests\Api\V1\Admin\Operations;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Services\Operations\BookingAdminQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dashboard booking list filters (slice-17-contract.md §5.1). Dates are Riyadh dates on `starts_at`.
 */
class ListBookingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(BookingStatus::class)],
            'filter.payment_status' => ['nullable', Rule::enum(BookingPaymentStatus::class)],
            'filter.has_pending_cancellation' => ['nullable', 'boolean'],
            'filter.provider_id' => ['nullable', 'integer'],
            'filter.client_id' => ['nullable', 'integer'],
            'filter.city_id' => ['nullable', 'integer'],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'sort' => ['nullable', Rule::in(BookingAdminQueries::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $int = fn (string $key) => $this->filled("filter.{$key}") ? (int) $this->validated("filter.{$key}") : null;
        $statuses = $this->validated('filter.status');

        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $statuses ? array_values($statuses) : null,
            'payment_status' => $this->validated('filter.payment_status'),
            'has_pending_cancellation' => $this->filled('filter.has_pending_cancellation') ? $this->boolean('filter.has_pending_cancellation') : null,
            'provider_id' => $int('provider_id'),
            'client_id' => $int('client_id'),
            'city_id' => $int('city_id'),
            'from' => $this->validated('filter.from'),
            'to' => $this->validated('filter.to'),
        ];
    }
}
