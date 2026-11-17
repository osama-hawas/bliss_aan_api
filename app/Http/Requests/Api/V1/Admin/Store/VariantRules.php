<?php

namespace App\Http\Requests\Api\V1\Admin\Store;

use App\Enums\ProductAttribute;

/**
 * Field rules shared by the product create body (`variants.*`) and the variant endpoints (D273).
 * Price `0 < x ≤ 99,999,999.99`, 2 decimals (a price is never 0, D34). Stock is a whole number ≥ 0.
 */
final class VariantRules
{
    public const SKU = ['string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'];

    public const PRICE = ['numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'];

    public const STOCK = ['integer', 'min:0', 'max:1000000'];

    /**
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $codes = array_column(ProductAttribute::cases(), 'value');
        $rules = [
            "{$prefix}sku" => [$required, ...self::SKU],
            "{$prefix}attributes" => ['sometimes', 'nullable', 'array:'.implode(',', $codes)],
            "{$prefix}price" => [$required, ...self::PRICE],
        ];
        foreach ($codes as $code) {
            $rules["{$prefix}attributes.{$code}"] = ['nullable', 'array:ar,en'];
            $rules["{$prefix}attributes.{$code}.ar"] = ["required_with:{$prefix}attributes.{$code}", 'string', 'max:40'];
            $rules["{$prefix}attributes.{$code}.en"] = ["required_with:{$prefix}attributes.{$code}", 'string', 'max:40'];
        }
        if ($creating) {
            $rules["{$prefix}stock"] = ['required', ...self::STOCK];
        }

        return $rules;
    }
}
