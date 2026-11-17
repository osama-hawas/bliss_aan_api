<?php

namespace App\Http\Resources\Api\V1\Shared\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Store\StoreMoney;
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
        // D209: the card shows "from" the lowest active variant price.
        $prices = $variants->map(fn (ProductVariant $variant) => (string) $variant->price)->unique(fn (string $p) => StoreMoney::cents($p));
        $lowest = $prices->sortBy(fn (string $p) => StoreMoney::cents($p))->first() ?? $this->base_price;

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'image_url' => $this->publicUrl($this->image),
            'price' => $this->money($lowest),
            'price_from' => $prices->count() > 1,
            'in_stock' => $variants->contains(fn (ProductVariant $variant) => $variant->inStock()),
            'variants_count' => $variants->count(),
            // The only active variant, so the app can add it to the cart without asking (null when there are several).
            'default_variant_id' => $variants->count() === 1 ? $variants->first()->id : null,
        ];
    }
}
