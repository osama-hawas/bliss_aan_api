<?php

namespace App\Services\Catalog;

use App\Enums\FavoriteType;
use App\Models\Favorite;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\User;
use App\Services\Providers\ProviderDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A client's saved providers and provider services. Adding and removing are idempotent.
 * Favorites of items that are no longer visible are kept, but not listed.
 */
class FavoriteService
{
    public function __construct(private readonly ProviderDirectory $directory) {}

    /**
     * @throws ModelNotFoundException when the item is not visible
     */
    public function add(User $user, FavoriteType $type, int $id): Favorite
    {
        $this->visibleTarget($type)->findOrFail($id);

        return Favorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'favoritable_type' => $type,
            'favoritable_id' => $id,
        ]);
    }

    public function remove(User $user, FavoriteType $type, int $id): void
    {
        Favorite::query()
            ->where('user_id', $user->id)
            ->where('favoritable_type', $type)
            ->where('favoritable_id', $id)
            ->delete();
    }

    public function list(User $user, ?FavoriteType $type, int $perPage): LengthAwarePaginator
    {
        $types = $type ? [$type] : FavoriteType::cases();

        return Favorite::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $query) use ($types) {
                foreach ($types as $type) {
                    $query->orWhere(fn (Builder $query) => $query->where('favoritable_type', $type)->whereIn('favoritable_id', $this->visibleTarget($type)->select($type === FavoriteType::Provider ? 'providers.id' : 'provider_services.id')));
                }
            })
            ->with([
                'provider' => fn ($query) => $query->select('providers.*')
                    ->selectSub($this->directory->startingPrice(), 'starting_price')
                    ->with(['city', 'avatar', 'cover', 'services' => fn ($services) => $services->offered()->with('service.category')]),
                'providerService' => fn ($query) => $query->with(['service.category', 'service.image', 'provider.avatar', 'provider.city']),
            ])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function visibleTarget(FavoriteType $type): Builder
    {
        return match ($type) {
            FavoriteType::Provider => Provider::query()->listed(),
            FavoriteType::ProviderService => ProviderService::query()->visible(),
        };
    }
}
