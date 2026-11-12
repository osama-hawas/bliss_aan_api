<?php

namespace App\Http\Requests\Api\V1\Client\ServiceRequests;

use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestType;
use App\Http\Requests\Concerns\ListsServiceRequests;
use App\Services\ServiceRequests\ServiceRequestQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListServiceRequestsRequest extends FormRequest
{
    use ListsServiceRequests;

    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(ServiceRequestStatus::class)],
            'filter.type' => ['nullable', Rule::enum(ServiceRequestType::class)],
            'sort' => ['nullable', Rule::in(ServiceRequestQueries::CLIENT_SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{status: list<string>, type: ?string}
     */
    public function filters(): array
    {
        return ['status' => $this->statuses(), 'type' => $this->validated('filter.type')];
    }
}
