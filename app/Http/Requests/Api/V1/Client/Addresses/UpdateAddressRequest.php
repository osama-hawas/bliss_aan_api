<?php

namespace App\Http\Requests\Api\V1\Client\Addresses;

/**
 * Any field of a saved address; the ones sent follow the same rules as on creation.
 */
class UpdateAddressRequest extends StoreAddressRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        // City and details may be left out, but not cleared.
        array_unshift($rules['city_id'], 'sometimes');
        array_unshift($rules['details'], 'sometimes');

        return $rules;
    }
}
