<?php

namespace App\Services\Catalog;

use App\Models\Package;
use App\Models\ProviderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Packages for the client app (OD39; docs/slice-24-contract.md §5). Only packages visible to clients (active with at least
 * one visible service, D352), with only their visible services. Never a package price (D350); each service carries the
 * "starting from" price of the market (D355).
 */
class PackageBrowser
{
    public const HOME_LIMIT = 10;

    /**
     * Visible packages in admin order. With an event type: packages linked to it plus packages with no event type
     * (= every occasion, D351).
     *
     * @return Collection<int, Package>
     */
    public function list(?int $eventTypeId = null, ?int $limit = null): Collection
    {
        return Package::query()->visibleToClients()
            ->when($eventTypeId, fn (Builder $q, int $type) => $q->where(fn (Builder $w) => $w
                ->whereHas('eventTypes', fn (Builder $t) => $t->whereKey($type))
                ->orWhereDoesntHave('eventTypes')))
            ->with(['image', 'visibleServices', 'eventTypes' => fn ($q) => $q->where('event_types.is_active', true)])
            ->orderBy('sort')->orderBy('id')
            ->when($limit, fn (Builder $q, int $n) => $q->limit($n))
            ->get();
    }

    /**
     * One visible package with its visible services (catalog add-ons and category for the request form) and their
     * starting prices; anything else is a 404.
     */
    public function find(int $id): Package
    {
        $package = Package::query()->visibleToClients()
            ->with([
                'image',
                'eventTypes' => fn ($q) => $q->where('event_types.is_active', true),
                'visibleServices' => fn ($q) => $q->with(['image', 'category', 'addons' => fn ($a) => $a->where('addons.is_active', true)]),
            ])
            ->findOrFail($id);

        $prices = $this->startingPrices($package->visibleServices->pluck('id')->all());
        foreach ($package->visibleServices as $service) {
            $row = $prices[$service->id] ?? null;
            $service->setAttribute('starting_price', $row?->starting_price);
            $service->setAttribute('providers_count', (int) ($row?->providers_count ?? 0));
        }

        return $package;
    }

    /**
     * D355 (keeps D34: the guide price is never shown on its own): per service, the lowest current price among visible
     * providers offering it (her custom price, else the guide price — the same as `ProviderDirectory::startingPrice`),
     * and how many such providers there are.
     *
     * @param  list<int>  $serviceIds
     * @return array<int, object{starting_price: ?string, providers_count: int}>
     */
    public function startingPrices(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        return ProviderService::query()->visible()
            ->join('services as priced_services', 'priced_services.id', '=', 'provider_services.service_id')
            ->whereIn('provider_services.service_id', $serviceIds)
            ->groupBy('provider_services.service_id')
            ->selectRaw('provider_services.service_id, MIN(COALESCE(provider_services.custom_price, priced_services.guide_price)) as starting_price, COUNT(DISTINCT provider_services.provider_id) as providers_count')
            ->toBase()
            ->get()
            ->keyBy('service_id')
            ->all();
    }
}
