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

    /**
     * Plain messages for the variant fields (QA 2026-11-23): the defaults named the raw key, e.g. «variants.0.price».
     *
     * @return array<string, string>
     */
    public static function messages(string $prefix): array
    {
        $m = fn (string $key) => __("store.admin.variant_fields.{$key}");
        $messages = [
            "{$prefix}sku.required" => $m('sku_required'),
            "{$prefix}sku.string" => $m('sku_format'),
            "{$prefix}sku.regex" => $m('sku_format'),
            "{$prefix}sku.min" => $m('sku_length'),
            "{$prefix}sku.max" => $m('sku_length'),
            "{$prefix}price.required" => $m('price_required'),
            "{$prefix}price.numeric" => $m('price_number'),
            "{$prefix}price.gt" => $m('price_positive'),
            "{$prefix}price.max" => $m('price_max'),
            "{$prefix}price.decimal" => $m('price_decimals'),
            "{$prefix}stock.required" => $m('stock_required'),
            "{$prefix}stock.integer" => $m('stock_integer'),
            "{$prefix}stock.min" => $m('stock_integer'),
            "{$prefix}stock.max" => $m('stock_max'),
        ];
        foreach (array_column(ProductAttribute::cases(), 'value') as $code) {
            foreach (['ar', 'en'] as $lang) {
                $messages["{$prefix}attributes.{$code}.{$lang}.required_with"] = $m("attribute_{$lang}_required");
                $messages["{$prefix}attributes.{$code}.{$lang}.max"] = $m('attribute_max');
            }
        }

        return $messages;
    }
}
