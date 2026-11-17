<?php

namespace App\Http\Resources\Api\V1\Provider\Setup;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Her public profile as she edits it: bio, photo, weekly hours, portfolio and the cities she covers.
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
            // S26: what her own public-profile screen shows (rating and count come from `provider/reviews` meta).
            'city' => $this->city ? ['id' => $this->city->id, 'name' => $this->city->name] : null,
            'started_year' => $this->started_year,
            'is_verified' => (bool) $this->is_verified,
            'accepts_open_requests' => $this->accepts_open_requests,
            'working_hours' => $this->workingHours->map(fn ($h) => [
                'weekday' => $h->weekday,
                'opens_at' => substr($h->opens_at, 0, 5),
                'closes_at' => substr($h->closes_at, 0, 5),
            ])->values(),
            'coverage' => $this->coverageCities->sortBy('sort')->map(fn ($city) => ['id' => $city->id, 'name' => $city->name])->values(),
            'portfolio' => $this->portfolioItems->map(fn (PortfolioItem $p) => [
                'id' => $p->id,
                'url' => $media->url($p->media),
                'caption' => $p->caption,
                'hidden_by_admin' => $p->hidden_by_admin,
                'hidden_reason' => $p->hidden_by_admin ? $p->hidden_reason : null,
            ])->values(),
        ];
    }

    public static function load(Provider $provider): Provider
    {
        return $provider->refresh()->load(['avatar', 'city', 'workingHours', 'portfolioItems.media', 'coverageCities']);
    }
}
