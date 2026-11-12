<?php

namespace App\Http\Requests\Api\V1\Provider\ServiceRequests;

use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestType;
use App\Http\Requests\Concerns\ListsServiceRequests;
use App\Services\ServiceRequests\ServiceRequestQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `filter[status]` is her recipient status (new / offer_sent / declined / closed).
 */
class ListInboxRequest extends FormRequest
{
    use ListsServiceRequests;

    public function rules(): array
    {
        return [
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => [Rule::enum(RecipientStatus::class)],
            'filter.type' => ['nullable', Rule::enum(ServiceRequestType::class)],
            'sort' => ['nullable', Rule::in(ServiceRequestQueries::PROVIDER_SORTS)],
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
