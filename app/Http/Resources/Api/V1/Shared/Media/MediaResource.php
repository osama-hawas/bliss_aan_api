<?php

namespace App\Http\Resources\Api\V1\Shared\Media;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Media;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'context' => $this->collection,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'duration' => $this->duration,
            'visibility' => $this->visibility->value,
            'url' => app(MediaService::class)->url($this->resource),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
