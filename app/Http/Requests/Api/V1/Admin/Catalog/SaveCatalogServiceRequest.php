<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use App\Enums\GuestCountMode;
use Illuminate\Validation\Rule;

/**
 * Catalog service form (docs/slice-16-contract.md §4.2). `guide_price`: null = not set (D16), else > 0 with two
 * decimals (D181). `reason` is required by the service when the guide price changes.
 */
class SaveCatalogServiceRequest extends CatalogFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => [$this->creating() ? 'required' : 'sometimes', 'integer', Rule::exists('service_categories', 'id')],
            ...$this->text('name', 2, 80, true),
            ...$this->text('description', 1, 1000, false, nullable: true),
            ...$this->image(),
            'guide_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'guest_count_mode' => ['sometimes', Rule::enum(GuestCountMode::class)],
            'inclusions' => ['sometimes', 'array', 'max:100'],
            'inclusions.*' => ['array:id,text'],
            'inclusions.*.id' => ['sometimes', 'nullable', 'integer', 'distinct'],
            'inclusions.*.text' => ['required', 'array:ar,en'],
            'inclusions.*.text.ar' => ['required', 'string', 'min:1', 'max:150'],
            'inclusions.*.text.en' => ['required', 'string', 'min:1', 'max:150'],
            'addon_ids' => ['sometimes', 'array', 'max:100'],
            'addon_ids.*' => ['integer', 'distinct', Rule::exists('addons', 'id')],
            'reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    protected function fields(): array
    {
        return ['category_id', 'name', 'description', 'image_media_id', 'guide_price', 'guest_count_mode', 'inclusions', 'addon_ids'];
    }
}
