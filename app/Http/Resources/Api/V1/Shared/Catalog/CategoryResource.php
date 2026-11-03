<?php

namespace App\Http\Resources\Api\V1\Shared\Catalog;

use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceCategory
 */
class CategoryResource extends JsonResource
{
    use PublicMediaUrl;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->publicUrl($this->image),
        ];
    }
}
