<?php

namespace App\Models;

use App\Enums\ProductAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One returned order line: name, options, SKU and unit price are copied from the order line (BRD rule 1).
 */
class StoreReturnItem extends Model
{
    protected $fillable = [
        'store_return_id', 'store_order_item_id', 'product_variant_id', 'name', 'options', 'sku', 'unit_price', 'quantity',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'options' => 'array',
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }

    public function storeReturn(): BelongsTo
    {
        return $this->belongsTo(StoreReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(StoreOrderItem::class, 'store_order_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function localizedName(): string
    {
        $name = $this->name ?? [];

        return $name[app()->getLocale()] ?? $name['ar'] ?? (string) reset($name);
    }

    /** @return list<array{key: string, label: string, value: string}> */
    public function resolvedOptions(): array
    {
        $values = $this->options ?? [];
        $locale = app()->getLocale();
        $out = [];
        foreach (ProductAttribute::cases() as $attribute) {
            $value = $values[$attribute->value] ?? null;
            if (is_array($value)) {
                $out[] = ['key' => $attribute->value, 'label' => $attribute->label(), 'value' => $value[$locale] ?? $value['ar'] ?? (string) reset($value)];
            }
        }

        return $out;
    }
}
