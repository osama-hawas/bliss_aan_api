<?php

namespace App\Http\Requests\Api\V1\Provider\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D73: switching the event location on needs `confirm: true`, the provider's explicit agreement to deliver the
 * service at the client's event location. Switching it off needs nothing else.
 */
class SetEventLocationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'at_event_location' => ['required', 'boolean'],
            'confirm' => ['accepted_if:at_event_location,true,1'],
        ];
    }
}
