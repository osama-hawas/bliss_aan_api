<?php

namespace App\Http\Resources\Api\V1\Shared\Store;

use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductCategory
 */
class ProductCategoryResource extends JsonResource
{
    use PublicMediaUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image_url' => $this->publicUrl($this->image),
        ];
    }
}
