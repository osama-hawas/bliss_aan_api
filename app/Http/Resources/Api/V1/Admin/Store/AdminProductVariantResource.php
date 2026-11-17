<?php

namespace App\Http\Resources\Api\V1\Admin\Store;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A variant with its exact stock (staff only; clients never see the number, D206).
 *
 * @mixin ProductVariant
 */
class AdminProductVariantResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'attributes' => $this->getAttribute('attributes') ?? (object) [],
            'label' => collect($this->resolvedAttributes())->pluck('value')->implode(' · ') ?: null,
            'price' => $this->money($this->price),
            'stock' => $this->stock,
            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
