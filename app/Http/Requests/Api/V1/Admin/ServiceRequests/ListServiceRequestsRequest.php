<?php

namespace App\Http\Requests\Api\V1\Admin\ServiceRequests;

use App\Enums\ExecutionPlace;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestType;
use App\Http\Requests\Concerns\ListsServiceRequests;
use App\Services\ServiceRequests\ServiceRequestQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dashboard list filters (§6.5). Event dates are Riyadh dates, compared with the stored `event_date`.
 */
class ListServiceRequestsRequest extends FormRequest
{
    use ListsServiceRequests;

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(ServiceRequestStatus::class)],
            'filter.type' => ['nullable', Rule::enum(ServiceRequestType::class)],
            'filter.execution_place' => ['nullable', Rule::enum(ExecutionPlace::class)],
            'filter.city_id' => ['nullable', 'integer'],
            'filter.service_id' => ['nullable', 'integer'],
            'filter.provider_id' => ['nullable', 'integer'],
            'filter.client_id' => ['nullable', 'integer'],
            'filter.event_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.event_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.event_from'],
            'sort' => ['nullable', Rule::in(ServiceRequestQueries::ADMIN_SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $int = fn (string $key) => $this->filled("filter.{$key}") ? (int) $this->validated("filter.{$key}") : null;

        return [
            'q' => $this->filled('q') ? trim($this->validated('q')) : null,
            'status' => $this->statuses(),
            'type' => $this->validated('filter.type'),
            'execution_place' => $this->validated('filter.execution_place'),
            'city_id' => $int('city_id'),
            'service_id' => $int('service_id'),
            'provider_id' => $int('provider_id'),
            'client_id' => $int('client_id'),
            'event_from' => $this->validated('filter.event_from'),
            'event_to' => $this->validated('filter.event_to'),
        ];
    }
}
