<?php

namespace App\Http\Resources\Api\V1\Shared\Onboarding;

use App\Http\Resources\Concerns\PublicMediaUrl;
use App\Http\Resources\Concerns\ResolvesTranslation;
use App\Models\OnboardingSlide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A slide as the mobile app shows it: text in the request language with fallback (D158), image URL or null (D365).
 *
 * @mixin OnboardingSlide
 */
class OnboardingSlideResource extends JsonResource
{
    use PublicMediaUrl, ResolvesTranslation;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->translated($this->resource, 'title'),
            'text' => $this->translated($this->resource, 'text'),
            'image_url' => $this->publicUrl($this->image),
        ];
    }
}
