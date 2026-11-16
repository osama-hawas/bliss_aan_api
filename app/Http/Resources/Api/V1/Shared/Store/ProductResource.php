<?php

namespace App\Http\Resources\Api\V1\Shared\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product card. Expects `variants` loaded with the active ones only. Exact stock counts are not exposed (Q-S9-3).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $variants = $this->variants;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'image_url' => $this->publicUrl($this->image),
            'price' => $this->money($this->base_price),
            'in_stock' => $variants->contains(fn (ProductVariant $variant) => $variant->inStock()),
            'variants_count' => $variants->count(),
            // The only active variant, so the app can add it to the cart without asking (null when there are several).
            'default_variant_id' => $variants->count() === 1 ? $variants->first()->id : null,
        ];
    }
}
