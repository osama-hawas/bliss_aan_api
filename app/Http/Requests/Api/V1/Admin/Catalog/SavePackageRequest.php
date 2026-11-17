<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use App\Services\Catalog\Admin\CatalogPackages;
use Illuminate\Validation\Rule;

/**
 * A service package (Slice 24 §4). **No price field** (D350). `service_ids` = the full list in display order (1..20, any
 * status: an inactive service stays hidden from clients until activated); `event_type_ids` = the full set (empty = every
 * occasion, D351).
 */
class SavePackageRequest extends CatalogFormRequest
{
    public function rules(): array
    {
        return [
            ...$this->text('name', 2, 80, true),
            ...$this->text('description', 1, 1000, false, nullable: true),
            ...$this->image(),
            'service_ids' => [$this->creating() ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.CatalogPackages::MAX_SERVICES],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')],
            'event_type_ids' => ['sometimes', 'array', 'max:50'],
            'event_type_ids.*' => ['integer', 'distinct', Rule::exists('event_types', 'id')],
        ];
    }

    protected function fields(): array
    {
        return ['name', 'description', 'image_media_id', 'service_ids', 'event_type_ids'];
    }

    public function payload(): array
    {
        $data = parent::payload();
        if (array_key_exists('service_ids', $data)) {
            // Display order is the order sent (parent::payload keeps the first occurrence of each id).
            $data['service_ids'] = array_values($data['service_ids']);
        }
        if (array_key_exists('event_type_ids', $data)) {
            $data['event_type_ids'] = array_values(array_unique(array_map('intval', $data['event_type_ids'] ?? [])));
        }

        return $data;
    }
}
