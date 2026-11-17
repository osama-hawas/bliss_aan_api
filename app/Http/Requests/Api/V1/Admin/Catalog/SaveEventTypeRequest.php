<?php

namespace App\Http\Requests\Api\V1\Admin\Catalog;

class SaveEventTypeRequest extends CatalogFormRequest
{
    public function rules(): array
    {
        return [
            // Required on PUT too: the name is the only field (D183a).
            ...$this->text('name', 2, 60, true),
        ];
    }

    protected function fields(): array
    {
        return ['name'];
    }
}
