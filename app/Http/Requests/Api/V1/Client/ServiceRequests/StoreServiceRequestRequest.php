<?php

namespace App\Http\Requests\Api\V1\Client\ServiceRequests;

use App\Enums\ExecutionPlace;
use App\Enums\ServiceRequestType;
use App\Services\Locations\CityRadius;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of a new request (§6.3). Business rules (visibility, coverage, guest mode, add-ons, files, matching)
 * are checked by ServiceRequestCreator after the idempotency replay. The `Idempotency-Key` header is validated as
 * `idempotency_key`.
 */
class StoreServiceRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', Rule::enum(ServiceRequestType::class)],
            'provider_id' => ['required_if:type,direct', 'prohibited_if:type,open', 'nullable', 'integer'],
            'service_id' => ['required', 'integer'],
            // Slice 24 (D353): an open request made from a package; checked by ServiceRequestCreator.
            'package_id' => ['nullable', 'prohibited_if:type,direct', 'integer'],
            'event_type_id' => ['required', 'integer', Rule::exists('event_types', 'id')->where('is_active', true)],
            'execution_place' => ['required', Rule::enum(ExecutionPlace::class)],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'address_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'array'],
            'address.city_id' => ['required_with:address', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'address.details' => ['required_with:address', 'string', 'max:255'],
            'address.lat' => ['required_with:address', 'numeric', 'between:-90,90'],
            'address.lng' => ['required_with:address', 'numeric', 'between:-180,180'],
            'address.label' => ['nullable', 'string', 'max:60'],
            'address.district' => ['nullable', 'string', 'max:100'],
            // BUG-015 (owner 2026-11-19): no business limit, only a technical one so the column never overflows.
            'guests_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer', 'distinct'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'media_ids' => ['nullable', 'array'],
            'media_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Place rules: an open request is for the event location only (D55); the event location needs exactly one of
     * `address_id` / `address`; a provider-location request takes no address (D66).
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['type', 'execution_place'])) {
                return;
            }
            $place = ExecutionPlace::from($this->input('execution_place'));
            $hasId = $this->filled('address_id');
            $hasAddress = $this->filled('address');

            if ($this->input('type') === ServiceRequestType::Open->value && $place !== ExecutionPlace::EventLocation) {
                $validator->errors()->add('execution_place', __('validation.custom.execution_place.open_event_only'));
            }
            if ($place === ExecutionPlace::EventLocation) {
                if (! $hasId && ! $hasAddress) {
                    $validator->errors()->add('address_id', __('validation.custom.address_id.required'));
                }
                if ($hasId && $hasAddress) {
                    $validator->errors()->add('address', __('validation.custom.address.prohibited_with_id'));
                }
                if ($hasAddress && ! $validator->errors()->hasAny(['address.city_id', 'address.lat', 'address.lng'])
                    && ! app(CityRadius::class)->allows((int) $this->input('address.city_id'), (float) $this->input('address.lat'), (float) $this->input('address.lng'))) {
                    $validator->errors()->add('address.lat', __('validation.custom.address.outside_city'));
                }
            } else {
                if ($hasId) {
                    $validator->errors()->add('address_id', __('validation.custom.address_id.provider_location'));
                }
                if ($hasAddress) {
                    $validator->errors()->add('address', __('validation.custom.address_id.provider_location'));
                }
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $data = $this->validated();
        unset($data['idempotency_key']);

        return $data;
    }
}
