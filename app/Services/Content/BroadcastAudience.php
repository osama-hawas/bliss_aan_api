<?php

namespace App\Services\Content;

use App\Enums\Audience;
use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\BroadcastCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a campaign reaches (Slice 20, D305): active clients of the city (`users.city_id`), or active providers
 * (account and provider both active) covering the city. No city = everyone of that app.
 */
class BroadcastAudience
{
    /**
     * @return Builder<User>
     */
    public function query(Audience $app, ?int $cityId): Builder
    {
        $query = User::query()->where('status', UserStatus::Active);

        if ($app === Audience::Client) {
            return $query->where('type', UserType::Client)
                ->when($cityId, fn (Builder $q, int $city) => $q->where('city_id', $city));
        }

        return $query->where('type', UserType::Provider)
            ->whereHas('provider', fn (Builder $p) => $p->where('status', ProviderStatus::Active)
                ->when($cityId, fn (Builder $q, int $city) => $q->whereHas('coverageAreas', fn (Builder $c) => $c->where('city_id', $city))));
    }

    public function count(BroadcastCampaign $campaign): int
    {
        return $this->query($campaign->app, $campaign->city_id)->count();
    }
}
