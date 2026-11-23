<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (`POST`: category, name, at least one variant) or edit (`PUT`: product fields only, all optional) a product
 * (contract §5.2). Variants are edited through their own endpoints after creation; stock only by adjustment (D274).
 */
class ProductRequest extends FormRequest
{
    public function messages(): array
    {
        return VariantRules::messages('variants.*.');
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $rules = [
            'category_id' => [$required, 'integer'],
            'name' => [$required, 'array:ar,en'],
            'name.ar' => ['required_with:name', 'string', 'min:2', 'max:120'],
            'name.en' => ['required_with:name', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'array:ar,en'],
            'description.ar' => ['nullable', 'string', 'max:2000'],
            'description.en' => ['nullable', 'string', 'max:2000'],
            'image_media_id' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
        if ($creating) {
            $rules['variants'] = ['required', 'array', 'min:1', 'max:50'];
            $rules['variants.*'] = ['array'];
            $rules += VariantRules::rules('variants.*.', true);
        }

        return $rules;
    }

    public function fields(): array
    {
        $data = $this->validated();
        if (isset($data['name'])) {
            $data['name'] = ['ar' => trim($data['name']['ar']), 'en' => trim($data['name']['en'])];
        }
        if (isset($data['category_id'])) {
            $data['category_id'] = (int) $data['category_id'];
        }

        return $data;
    }
}
