<?php

namespace App\Services\Catalog;

use App\Enums\Audience;
use App\Models\Banner;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of the admin catalog for the client app. Only active rows are visible.
 */
class CatalogBrowser
{
    /**
     * @return Collection<int, ServiceCategory>
     */
    public function categories(): Collection
    {
        return ServiceCategory::query()->where('is_active', true)->with('image')->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Service>
     */
    public function services(?int $categoryId = null): Collection
    {
        return Service::query()
            ->where('is_active', true)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true))
            ->when($categoryId, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->with('image')
            ->orderBy('category_id')->orderBy('sort')->orderBy('id')
            ->get();
    }

    /**
     * Client-app banners running now, for every city or for the given one.
     *
     * @return Collection<int, Banner>
     */
    public function banners(?int $cityId): Collection
    {
        return Banner::query()
            ->where('app', Audience::Client)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn (Builder $query) => $query->whereNull('city_id')->when($cityId, fn (Builder $query, int $id) => $query->orWhere('city_id', $id)))
            ->with('image')
            ->orderBy('sort')->orderBy('id')
            ->get();
    }
}
