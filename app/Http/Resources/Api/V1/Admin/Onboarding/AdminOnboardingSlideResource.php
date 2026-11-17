<?php

namespace App\Http\Resources\Api\V1\Admin\Onboarding;

use App\Enums\ContentStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Models\OnboardingSlide;
use App\Services\Content\ContentTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnboardingSlide
 */
class AdminOnboardingSlideResource extends JsonResource
{
    use FormatsApiValues, PublicMediaUrl;

    public function toArray(Request $request): array
    {
        $status = ContentStatus::of((bool) $this->is_active);

        return [
            'id' => $this->id,
            'app' => $this->app->value,
            'title' => ContentTranslations::of($this->resource, 'title'),
            'text' => ContentTranslations::of($this->resource, 'text'),
            'missing' => ContentTranslations::missing($this->resource, ['title', 'text']),
            'image_url' => $this->publicUrl($this->image),
            'media_id' => $this->image?->id,
            'sort' => $this->sort,
            'status' => $status->value,
            'status_label' => $status->label(),
            'hidden_reason' => $this->hidden_reason,
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
