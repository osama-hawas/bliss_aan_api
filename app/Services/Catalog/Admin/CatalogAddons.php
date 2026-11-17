<?php

namespace App\Services\Catalog\Admin;

use App\Models\Addon;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Catalog add-ons in the dashboard (Req 12, 33, 55; docs/slice-16-contract.md §4.3). The admin sets no price: providers
 * price catalog add-ons themselves (Slice 3). Unlinking a service removes the `addon_service` row only; providers'
 * `provider_addons` rows are kept and hidden by D36 (D179). Stopping hides it from new requests, service details and
 * provider setup; request snapshots are unchanged (D180).
 */
class CatalogAddons
{
    public function __construct(
        private readonly CatalogRows $rows,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, service_id: ?int}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->query();
        CatalogRows::search($query, $filters['q'], ['name', 'description']);
        $query
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->when($filters['service_id'], fn (Builder $q, int $service) => $q->whereHas('services', fn (Builder $s) => $s->whereKey($service)));

        return CatalogRows::sort($query, $sort, 'addons')->paginate($perPage);
    }

    public function find(int $id): Addon
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @param  array{name: array, description?: ?array, image_media_id?: ?int, service_ids?: list<int>}  $data
     */
    public function create(array $data, User $actor): Addon
    {
        return DB::transaction(function () use ($data, $actor) {
            $addon = new Addon(['is_active' => true, 'sort' => $this->rows->nextSort(Addon::query())]);
            $this->rows->fillText($addon, $data, ['name', 'description']);
            if (array_key_exists('image_media_id', $data)) {
                $this->rows->setImage($addon, $actor, $data['image_media_id']);
            }
            if (array_key_exists('service_ids', $data)) {
                $addon->services()->sync($data['service_ids']);
            }
            $this->audit->log('catalog_addon_created', $actor, $addon, null, null, $this->values($addon));

            return $this->find($addon->id);
        });
    }

    /**
     * @param  array{name?: array, description?: ?array, image_media_id?: ?int, service_ids?: list<int>}  $data
     */
    public function update(int $id, array $data, User $actor): Addon
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $addon = Addon::query()->lockForUpdate()->findOrFail($id);
            $before = $this->values($addon);

            $this->rows->fillText($addon, $data, ['name', 'description']);
            if (array_key_exists('image_media_id', $data)) {
                $this->rows->setImage($addon, $actor, $data['image_media_id']);
            }
            if (array_key_exists('service_ids', $data)) {
                $addon->services()->sync($data['service_ids']);
            }

            [$old, $new] = CatalogCategories::diff($before, $this->values($addon));
            if ($new !== []) {
                $this->audit->log('catalog_addon_updated', $actor, $addon, null, $old, $new);
            }

            return $this->find($addon->id);
        });
    }

    public function stop(int $id, User $actor, string $reason, ?string $internalNote): Addon
    {
        $this->rows->setActive(Addon::class, $id, false, $actor, $reason, $internalNote, 'catalog_addon');

        return $this->find($id);
    }

    public function activate(int $id, User $actor, ?string $reason): Addon
    {
        $this->rows->setActive(Addon::class, $id, true, $actor, $reason, null, 'catalog_addon');

        return $this->find($id);
    }

    public function move(int $id, string $direction, User $actor): Addon
    {
        $this->rows->move($this->find($id), Addon::query(), $direction, $actor, 'catalog_addon_moved');

        return $this->find($id);
    }

    private function query(): Builder
    {
        return Addon::query()
            ->with(['image', 'services' => fn ($q) => $q->orderBy('services.sort')->orderBy('services.id')])
            ->withCount(['services', 'providerAddons as providers_count' => fn (Builder $q) => $q->where('is_active', true)]);
    }

    private function values(Addon $addon): array
    {
        return [
            'name' => $addon->getTranslations('name'),
            'description' => $addon->getTranslations('description') ?: null,
            'image_media_id' => $this->rows->imageId($addon),
            'service_ids' => $addon->services()->pluck('services.id')->sort()->values()->all(),
        ];
    }
}
