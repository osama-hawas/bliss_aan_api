<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (`POST`, name required) or edit (`PUT`, every field optional) a store category (contract §5.1).
 */
class ProductCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'array:ar,en'],
            'name.ar' => ['required_with:name', 'string', 'min:2', 'max:60'],
            'name.en' => ['required_with:name', 'string', 'min:2', 'max:60'],
            'image_media_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function fields(): array
    {
        $data = $this->validated();
        if (isset($data['name'])) {
            $data['name'] = ['ar' => trim($data['name']['ar']), 'en' => trim($data['name']['en'])];
        }

        return $data;
    }
}
