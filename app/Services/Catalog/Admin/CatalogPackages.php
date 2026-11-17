<?php

namespace App\Services\Catalog\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Package;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service packages in the dashboard (OD39; docs/slice-24-contract.md §4). A package bundles catalog services with a
 * bilingual name, description, image, optional event types and an order, and **never a price** (D350): providers price
 * each service through offers. Rows are stopped (reason kept on the row) and activated, never deleted (D180). Stopping a
 * package never touches requests made from it (their `package_id` stays, D353). Every write runs on the locked row and
 * writes one audit row (S16 mechanics in `CatalogRows`).
 */
class CatalogPackages
{
    public const MAX_SERVICES = 20;

    public function __construct(
        private readonly CatalogRows $rows,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, service_id: ?int, event_type_id?: ?int}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->query();
        CatalogRows::search($query, $filters['q'], ['name', 'description']);
        $query
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->when($filters['service_id'], fn (Builder $q, int $service) => $q->whereHas('services', fn (Builder $s) => $s->whereKey($service)))
            ->when($filters['event_type_id'] ?? null, fn (Builder $q, int $type) => $q->whereHas('eventTypes', fn (Builder $t) => $t->whereKey($type)));

        return CatalogRows::sort($query, $sort, 'packages')->paginate($perPage);
    }

    public function find(int $id): Package
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @param  array{name: array, description?: ?array, image_media_id?: ?int, service_ids: list<int>, event_type_ids?: list<int>}  $data
     */
    public function create(array $data, User $actor): Package
    {
        return DB::transaction(function () use ($data, $actor) {
            $package = new Package(['is_active' => true, 'sort' => $this->rows->nextSort(Package::query())]);
            $this->rows->fillText($package, $data, ['name', 'description']);
            $this->apply($package, $data, $actor);
            $this->audit->log('catalog_package_created', $actor, $package, null, null, $this->values($package));

            return $this->find($package->id);
        });
    }

    /**
     * Absent keys stay unchanged. `service_ids` is the full list in display order; `event_type_ids` the full set.
     *
     * @param  array{name?: array, description?: ?array, image_media_id?: ?int, service_ids?: list<int>, event_type_ids?: list<int>}  $data
     */
    public function update(int $id, array $data, User $actor): Package
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $package = Package::query()->lockForUpdate()->findOrFail($id);
            $before = $this->values($package);

            $this->rows->fillText($package, $data, ['name', 'description']);
            $this->apply($package, $data, $actor);

            [$old, $new] = CatalogCategories::diff($before, $this->values($package));
            if ($new !== []) {
                $this->audit->log('catalog_package_updated', $actor, $package, null, $old, $new);
            }

            return $this->find($package->id);
        });
    }

    /**
     * Hidden from clients at once; requests already made from it continue unchanged (D180, D353). The reason is kept on
     * the row for the dashboard (cleared on activation) and in the audit row. Same mechanics and audit shape as
     * `CatalogRows::setActive`, with `stop_reason` written in the same locked transaction.
     */
    public function stop(int $id, User $actor, string $reason, ?string $internalNote): Package
    {
        $this->setActive($id, false, $actor, $reason, $internalNote);

        return $this->find($id);
    }

    public function activate(int $id, User $actor, ?string $reason): Package
    {
        $this->setActive($id, true, $actor, $reason, null);

        return $this->find($id);
    }

    public function move(int $id, string $direction, User $actor): Package
    {
        $this->rows->move($this->find($id), Package::query(), $direction, $actor, 'catalog_package_moved');

        return $this->find($id);
    }

    private function setActive(int $id, bool $active, User $actor, ?string $reason, ?string $internalNote): void
    {
        DB::transaction(function () use ($id, $active, $actor, $reason, $internalNote) {
            $package = Package::query()->lockForUpdate()->findOrFail($id);
            if ($package->is_active === $active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $package->forceFill(['is_active' => $active, 'stop_reason' => $active ? null : $reason])->save();

            $this->audit->log(
                'catalog_package'.($active ? '_activated' : '_stopped'),
                $actor,
                $package,
                $reason,
                ['is_active' => ! $active],
                array_filter(['is_active' => $active, 'internal_note' => $internalNote], fn ($v) => $v !== null),
            );
        });
    }

    /**
     * Image, services (full ordered list: rows rewritten, D354) and event types.
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(Package $package, array $data, User $actor): void
    {
        if (array_key_exists('image_media_id', $data)) {
            $this->rows->setImage($package, $actor, $data['image_media_id']);
        }
        if (array_key_exists('service_ids', $data)) {
            $ids = array_values($data['service_ids']);
            $package->items()->whereNotIn('service_id', $ids)->delete();
            foreach ($ids as $index => $serviceId) {
                $package->items()->updateOrCreate(['service_id' => $serviceId], ['sort' => $index + 1]);
            }
        }
        if (array_key_exists('event_type_ids', $data)) {
            $package->eventTypes()->sync($data['event_type_ids']);
        }
    }

    private function query(): Builder
    {
        return Package::query()
            ->with([
                'image',
                'services' => fn ($q) => $q->with('category:id,is_active'),
                'eventTypes',
            ])
            ->withCount(['services', 'serviceRequests as requests_count']);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Package $package): array
    {
        return [
            'name' => $package->getTranslations('name'),
            'description' => $package->getTranslations('description') ?: null,
            'image_media_id' => $this->rows->imageId($package),
            'service_ids' => $package->items()->pluck('service_id')->map(fn ($id) => (int) $id)->values()->all(),
            'event_type_ids' => $package->eventTypes()->pluck('event_types.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }
}
