<?php

namespace App\Models;

use App\Enums\ProductAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered variant: name, options, SKU and unit price are snapshots (BRD rule 1).
 */
class StoreOrderItem extends Model
{
    protected $fillable = [
        'store_order_id', 'product_variant_id', 'product_id', 'name', 'options', 'sku', 'unit_price', 'quantity',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function localizedName(): string
    {
        $name = $this->name ?? [];

        return $name[app()->getLocale()] ?? $name['ar'] ?? (string) reset($name);
    }

    /**
     * Snapshot options in the request language: [{key, label, value}].
     *
     * @return list<array{key: string, label: string, value: string}>
     */
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
