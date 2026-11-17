<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Validation\Rule;

/**
 * No price: providers price catalog add-ons themselves (Slice 3).
 */
class SaveAddonRequest extends CatalogFormRequest
{
    public function rules(): array
    {
        return [
            ...$this->text('name', 2, 60, true),
            ...$this->text('description', 1, 400, false, nullable: true),
            ...$this->image(),
            'service_ids' => ['sometimes', 'array', 'max:200'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')],
        ];
    }

    protected function fields(): array
    {
        return ['name', 'description', 'image_media_id', 'service_ids'];
    }
}
