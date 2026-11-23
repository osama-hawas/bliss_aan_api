<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a variant (`POST admin/products/{id}/variants`: sku, attributes, price, initial stock) or edit one
 * (`PUT admin/product-variants/{id}`: sku, attributes, price — never stock, D274).
 */
class VariantRequest extends FormRequest
{
    public function messages(): array
    {
        return VariantRules::messages('');
    }

    public function rules(): array
    {
        return VariantRules::rules('', $this->isMethod('post'));
    }
}
