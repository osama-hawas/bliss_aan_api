<?php

namespace App\Http\Resources\Api\V1\Shared\Store;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * Product details with its active variants.
 *
 * @mixin Product
 */
class ProductDetailResource extends ProductResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'variants' => $this->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'attributes' => $variant->resolvedAttributes(),
                'price' => $this->money($variant->price),
                'in_stock' => $variant->inStock(),
            ])->values(),
        ];
    }
}
