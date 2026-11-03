<?php

namespace App\Services\Providers;

use App\Models\Provider;
use App\Models\ProviderAddon;
use App\Models\ProviderService;
use App\Models\ServiceInclusion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What clients can find about providers: search, featured list, public profile, service details.
 * Everything goes through Provider::listed() and ProviderService::offered(); anything else is a 404.
 */
class ProviderDirectory
{
    /**
     * Whitelisted `sort` values. C7 (fast replies raise ranking) is open, so ranking is by rating only.
     */
    public const SORTS = ['-rating', 'rating', 'price', '-price', '-activated_at', 'activated_at'];

    public const DEFAULT_SORT = '-rating';

    public const FEATURED_LIMIT = 10;

    /**
     * @param  array{q?: ?string, city_id?: ?int, category_id?: ?int, service_id?: ?int, min_rating?: ?float, verified?: ?bool}  $filters
     */
    public function search(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->cards($filters)
            ->when($filters['city_id'] ?? null, fn (Builder $query, int $id) => $this->coveringCity($query, $id))
            ->when($filters['verified'] ?? null, fn (Builder $query) => $query->where('providers.is_verified', true))
            ->when($filters['min_rating'] ?? null, fn (Builder $query, float $rating) => $query->where('providers.rating_avg', '>=', $rating))
            ->when($filters['q'] ?? null, fn (Builder $query, string $text) => $this->matching($query, $text));

        $this->sort($query, $sort ?? self::DEFAULT_SORT);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Admin-picked providers (D32), limited to the client's city when known.
     *
     * @return Collection<int, Provider>
     */
    public function featured(?int $cityId): Collection
    {
        $query = $this->cards([])
            ->where('providers.is_featured', true)
            ->when($cityId, fn (Builder $query, int $id) => $this->coveringCity($query, $id));

        $this->sort($query, self::DEFAULT_SORT);

        return $query->limit(self::FEATURED_LIMIT)->get();
    }

    public function profile(int $providerId): Provider
    {
        return Provider::query()->listed()
            ->with([
                'city', 'avatar', 'cover', 'coverageCities',
                'services' => fn ($query) => $query->offered()->with(['service.category', 'service.image'])->orderBy('id'),
                'portfolioItems' => fn ($query) => $query->published()->with('media'),
            ])
            ->findOrFail($providerId);
    }

    /**
     * A visible service of a visible provider; the pair must match.
     */
    public function service(int $providerId, int $providerServiceId): ProviderService
    {
        return ProviderService::query()->visible()
            ->where('provider_id', $providerId)
            ->with(['service.category', 'service.image', 'provider.avatar', 'provider.city'])
            ->findOrFail($providerServiceId);
    }

    /**
     * Catalog inclusions the provider has not switched off.
     *
     * @return Collection<int, ServiceInclusion>
     */
    public function inclusions(ProviderService $providerService): Collection
    {
        $disabled = $providerService->inclusionSettings()->where('enabled', false)->pluck('service_inclusion_id');

        return $providerService->service->inclusions()->whereNotIn('id', $disabled)->get();
    }

    /**
     * Only add-ons the provider offers with this service, still active in the catalog and linked to it there.
     *
     * @return Collection<int, ProviderAddon>
     */
    public function addons(ProviderService $providerService): Collection
    {
        return $providerService->addons()
            ->where('provider_addons.is_active', true)
            ->whereHas('addon', fn (Builder $addon) => $addon->where('is_active', true)
                ->whereHas('services', fn (Builder $service) => $service->whereKey($providerService->service_id)))
            ->with('addon')
            ->orderBy('provider_addons.id')
            ->get();
    }

    /**
     * Subquery for `starting_price`: the provider's lowest current price among her offered services
     * matching the filters (custom price, else guide price; null when none is set).
     */
    public function startingPrice(array $filters = []): Builder
    {
        $query = ProviderService::query()
            ->join('services as priced_services', 'priced_services.id', '=', 'provider_services.service_id')
            ->whereColumn('provider_services.provider_id', 'providers.id')
            ->selectRaw('MIN(COALESCE(provider_services.custom_price, priced_services.guide_price))');
        $this->matchingServices($query, $filters);

        return $query;
    }

    /**
     * Listed providers with at least one matching offered service, with their `starting_price`.
     */
    private function cards(array $filters): Builder
    {
        return Provider::query()->listed()
            ->whereHas('services', fn (Builder $query) => $this->matchingServices($query, $filters))
            ->select('providers.*')
            ->selectSub($this->startingPrice($filters), 'starting_price')
            ->with([
                'city', 'avatar', 'cover',
                'services' => fn ($query) => $query->offered()->with('service.category'),
            ]);
    }

    private function matchingServices(Builder $query, array $filters): void
    {
        $query->offered()
            ->when($filters['service_id'] ?? null, fn (Builder $query, int $id) => $query->where('provider_services.service_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('service', fn (Builder $service) => $service->where('category_id', $id)));
    }

    private function coveringCity(Builder $query, int $cityId): void
    {
        $query->whereHas('coverageAreas', fn (Builder $area) => $area->where('city_id', $cityId));
    }

    /**
     * Provider name, or the name of one of her offered services, in either language.
     */
    private function matching(Builder $query, string $text): void
    {
        $like = '%'.addcslashes($text, '\\%_').'%';

        $query->where(fn (Builder $query) => $query
            ->where('providers.display_name', 'like', $like)
            ->orWhereHas('services', fn (Builder $services) => $services->offered()
                ->whereHas('service', fn (Builder $service) => $service
                    ->where(fn (Builder $name) => $name->where('name->ar', 'like', $like)->orWhere('name->en', 'like', $like)))));
    }

    /**
     * Providers without a rating or a price always go last.
     */
    private function sort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        match (ltrim($sort, '-')) {
            'rating' => $query->orderByRaw('providers.rating_avg IS NULL')
                ->orderBy('providers.rating_avg', $direction)
                ->orderBy('providers.rating_count', $direction),
            'price' => $query->orderByRaw('starting_price IS NULL')->orderBy('starting_price', $direction),
            'activated_at' => $query->orderBy('providers.activated_at', $direction),
        };

        $query->orderBy('providers.id');
    }
}
