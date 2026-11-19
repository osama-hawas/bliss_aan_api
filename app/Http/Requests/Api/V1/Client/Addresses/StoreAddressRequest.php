<?php

namespace App\Http\Requests\Api\V1\Client\Addresses;

use App\Rules\SaudiMobile;
use App\Services\Locations\CityRadius;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A saved address (§6.2). District is free text (D59); the pin is optional on the address itself but required when
 * it is used for an event-location request.
 */
class StoreAddressRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'details' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:60'],
            'district' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'recipient_name' => ['nullable', 'string', 'max:60'],
            'recipient_phone' => ['nullable', new SaudiMobile],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** A pinned address must be in its city (QA BUG-006). */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['city_id', 'lat', 'lng']) || ! $this->filled('lat') || ! $this->filled('lng') || ! $this->filled('city_id')) {
                return;
            }
            if (! app(CityRadius::class)->allows((int) $this->input('city_id'), (float) $this->input('lat'), (float) $this->input('lng'))) {
                $validator->errors()->add('lat', __('validation.custom.address.outside_city'));
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function addressData(): array
    {
        $data = $this->validated();
        if (array_key_exists('is_default', $data)) {
            $data['is_default'] = (bool) $data['is_default'];
        }

        return $data;
    }
}
