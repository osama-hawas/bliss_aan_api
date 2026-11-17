<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

/**
 * The admin package list: the catalog list filters plus `filter[event_type_id]` (Slice 24 §4).
 */
class PackageListRequest extends CatalogListRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'filter.event_type_id' => ['nullable', 'integer']];
    }

    public function filters(): array
    {
        return [
            ...parent::filters(),
            'event_type_id' => $this->filled('filter.event_type_id') ? (int) $this->validated('filter.event_type_id') : null,
        ];
    }
}
