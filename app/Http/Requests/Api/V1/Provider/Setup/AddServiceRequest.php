<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where she delivers it (D74): at least one place for a new service (checked in ProviderSetup). Choosing the
 * client's event location needs `confirm: true`, her explicit confirmation (D73).
 */
class AddServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer'],
            'at_event_location' => ['sometimes', 'boolean'],
            'at_provider_location' => ['sometimes', 'boolean'],
            'confirm' => ['accepted_if:at_event_location,true,1'],
        ];
    }

    /**
     * @return array{at_event_location?: bool, at_provider_location?: bool}
     */
    public function places(): array
    {
        return array_map(fn ($value) => (bool) $value, $this->safe()->only(['at_event_location', 'at_provider_location']));
    }
}
