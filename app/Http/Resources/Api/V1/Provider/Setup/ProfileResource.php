<?php

namespace App\Http\Resources\Api\V1\Provider\Setup;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her public profile as she edits it: bio, photo, weekly hours and portfolio.
 *
 * @mixin Provider
 */
class ProfileResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);

        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar ? $media->url($this->avatar) : null,
            'accepts_open_requests' => $this->accepts_open_requests,
            'working_hours' => $this->workingHours->map(fn ($h) => [
                'weekday' => $h->weekday,
                'opens_at' => substr($h->opens_at, 0, 5),
                'closes_at' => substr($h->closes_at, 0, 5),
            ])->values(),
            'portfolio' => $this->portfolioItems->map(fn (PortfolioItem $p) => [
                'id' => $p->id,
                'url' => $media->url($p->media),
                'caption' => $p->caption,
                'hidden_by_admin' => $p->hidden_by_admin,
            ])->values(),
        ];
    }

    public static function load(Provider $provider): Provider
    {
        return $provider->refresh()->load(['avatar', 'workingHours', 'portfolioItems.media']);
    }
}
