<?php

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Enums\ContentStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\Banner;
use App\Services\Content\BannerManagement;
use App\Services\Content\ContentTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Banner
 */
class AdminBannerResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $type = array_search($this->destination_type, BannerManagement::DESTINATIONS, true);
        $status = ContentStatus::of((bool) $this->is_active);

        return [
            'id' => $this->id,
            'title' => ContentTranslations::of($this->resource, 'title'),
            'missing' => ContentTranslations::missing($this->resource, ['title']),
            'image_url' => $this->publicUrl($this->image),
            'media_id' => $this->image?->id,
            'destination' => $type !== false && $this->destination_id ? ['type' => $type, 'id' => $this->destination_id] : null,
            'city_id' => $this->city_id,
            'city' => $this->city ? ['id' => $this->city->id, 'name' => $this->city->getTranslations('name')] : null,
            'starts_at' => $this->dateTime($this->starts_at),
            'ends_at' => $this->dateTime($this->ends_at),
            'sort' => $this->sort,
            'status' => $status->value,
            'status_label' => $status->label(),
            'window' => $this->window(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }

    /**
     * Where `now` falls in the display window: `scheduled` (not started), `ended`, or `running`.
     */
    private function window(): string
    {
        return match (true) {
            $this->starts_at !== null && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at !== null && $this->ends_at->isPast() => 'ended',
            default => 'running',
        };
    }
}
