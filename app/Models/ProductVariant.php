<?php

namespace App\Models;

use App\Enums\ProductAttribute;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sellable variant of a product: its own SKU, price and stock (BRD Req 46).
 * `attributes` maps a ProductAttribute code to an {ar, en} value. Stock is never reserved or deducted in Slice 9.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'sku', 'attributes', 'price', 'stock', 'is_active'];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'price' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Purchasable now: the variant, its product and the product's category are active.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('product_variants.is_active', true)
            ->whereHas('product', fn (Builder $product) => $product->where('is_active', true)
                ->whereHas('category', fn (Builder $category) => $category->where('is_active', true)));
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->product->is_active && $this->product->category->is_active;
    }

    public function inStock(): bool
    {
        return $this->stock > 0;
    }

    /**
     * Attributes in the request language, in ProductAttribute order: [{key, label, value}].
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    public function resolvedAttributes(): array
    {
        $values = $this->getAttribute('attributes') ?? [];
        $locale = app()->getLocale();
        $out = [];

        foreach (ProductAttribute::cases() as $attribute) {
            $value = $values[$attribute->value] ?? null;
            if (! is_array($value)) {
                continue;
            }
            $out[] = [
                'key' => $attribute->value,
                'label' => $attribute->label(),
                'value' => $value[$locale] ?? $value['ar'] ?? (string) reset($value),
            ];
        }

        return $out;
    }
}
