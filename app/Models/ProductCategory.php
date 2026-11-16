<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Translatable\HasTranslations;

/**
 * Store category (admin-run store, D1). Managed in Slice 18; read by the store catalog (Slice 9).
 */
class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory, HasMedia, HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['name', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function image(): MorphOne
    {
        return $this->mediaIn('image');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
