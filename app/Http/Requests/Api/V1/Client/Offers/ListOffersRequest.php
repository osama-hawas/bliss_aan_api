<?php

namespace App\Http\Requests\Api\V1\Client\Offers;

use App\Services\ServiceRequests\ServiceRequestQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOffersRequest extends FormRequest
{
    public function rules(): array
    {
        return ['sort' => ['nullable', Rule::in(ServiceRequestQueries::OFFER_SORTS)]];
    }
}
