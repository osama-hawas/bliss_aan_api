<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

class SaveCategoryRequest extends CatalogFormRequest
{
    public function rules(): array
    {
        return [
            ...$this->text('name', 2, 60, true),
            ...$this->text('description', 1, 400, false, nullable: true),
            ...$this->image(),
        ];
    }

    protected function fields(): array
    {
        return ['name', 'description', 'image_media_id'];
    }
}
