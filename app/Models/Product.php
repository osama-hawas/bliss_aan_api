<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Translatable\HasTranslations;

/**
 * A store product (Slice 9). What is sold and put in the cart is one of its variants.
 * Seeded only; management is Slice 18.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasMedia, HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = ['category_id', 'name', 'description', 'base_price', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['base_price' => 'decimal:2', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function image(): MorphOne
    {
        return $this->mediaIn('image');
    }

    /**
     * Visible to the apps: active, in an active category, with at least one active variant (`slice-9-contract.md` §4).
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('products.is_active', true)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true))
            ->whereHas('variants', fn (Builder $variant) => $variant->where('is_active', true));
    }
}
