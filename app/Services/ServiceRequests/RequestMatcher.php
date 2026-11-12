<?php

namespace App\Services\ServiceRequests;

use App\Models\Addon;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who receives an open request (§7.2, D55/D59/D63/D65/D73). A provider matches when, at this moment, she:
 *  1. is visible to clients (D34 as amended by D75);
 *  2. offers the service with a confirmed event-location capability (D73; unconfirmed rows never match);
 *  3. accepts open requests;
 *  4. covers the request city (districts are not used, D59);
 *  5. offers every requested add-on with that service.
 * No availability check in Slice 4 (D70).
 */
class RequestMatcher
{
    /**
     * @param  list<int>  $addonIds
     * @return Collection<int, int> provider ids
     */
    public function openRecipients(int $serviceId, int $cityId, array $addonIds): Collection
    {
        return $this->query($serviceId, $cityId, $addonIds)->orderBy('providers.id')->pluck('providers.id');
    }

    /**
     * The `open_request_available` hint (§7.3): a boolean only, never counts or ids. Add-ons that are not active
     * catalog add-ons of the service make it false, as creation would refuse them.
     *
     * @param  list<int>  $addonIds
     */
    public function openRequestAvailable(int $serviceId, int $cityId, array $addonIds): bool
    {
        if (! $this->catalogAddonsValid($serviceId, $addonIds)) {
            return false;
        }

        return $this->query($serviceId, $cityId, $addonIds)->exists();
    }

    /**
     * Every id is an active catalog add-on linked to the service.
     *
     * @param  list<int>  $addonIds
     */
    public function catalogAddonsValid(int $serviceId, array $addonIds): bool
    {
        if ($addonIds === []) {
            return true;
        }

        $valid = Addon::query()
            ->whereKey($addonIds)
            ->where('is_active', true)
            ->whereHas('services', fn (Builder $s) => $s->whereKey($serviceId))
            ->count();

        return $valid === count(array_unique($addonIds));
    }

    /**
     * @param  list<int>  $addonIds
     */
    private function query(int $serviceId, int $cityId, array $addonIds): Builder
    {
        return Provider::query()
            ->visibleToClients()
            ->where('providers.accepts_open_requests', true)
            ->whereHas('coverageAreas', fn (Builder $area) => $area->where('city_id', $cityId))
            ->whereHas('services', function (Builder $offered) use ($serviceId, $addonIds) {
                $offered->offered()->openRequestCapable()->where('provider_services.service_id', $serviceId);
                foreach (array_unique($addonIds) as $addonId) {
                    $offered->whereHas('addons', fn (Builder $priced) => $priced
                        ->where('provider_addons.is_active', true)
                        ->where('provider_addons.addon_id', $addonId));
                }
            });
    }
}
