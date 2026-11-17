<?php

namespace App\Services\Catalog\Admin;

use App\Models\EventType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Event types in the dashboard (D183a; docs/slice-16-contract.md §4.5). Stopping one hides it from new requests only
 * (`StoreServiceRequestRequest` accepts active types); existing requests keep their `event_type_id`.
 */
class CatalogEventTypes
{
    public function __construct(
        private readonly CatalogRows $rows,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->query();
        CatalogRows::search($query, $filters['q'], ['name']);
        $query->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'));

        return CatalogRows::sort($query, $sort, 'event_types')->paginate($perPage);
    }

    public function find(int $id): EventType
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @param  array{name: array}  $data
     */
    public function create(array $data, User $actor): EventType
    {
        return DB::transaction(function () use ($data, $actor) {
            $type = new EventType(['is_active' => true, 'sort' => $this->rows->nextSort(EventType::query())]);
            $this->rows->fillText($type, $data, ['name']);
            $this->audit->log('catalog_event_type_created', $actor, $type, null, null, ['name' => $type->getTranslations('name')]);

            return $this->find($type->id);
        });
    }

    /**
     * @param  array{name: array}  $data
     */
    public function update(int $id, array $data, User $actor): EventType
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $type = EventType::query()->lockForUpdate()->findOrFail($id);
            $before = ['name' => $type->getTranslations('name')];
            $this->rows->fillText($type, $data, ['name']);
            [$old, $new] = CatalogCategories::diff($before, ['name' => $type->getTranslations('name')]);
            if ($new !== []) {
                $this->audit->log('catalog_event_type_updated', $actor, $type, null, $old, $new);
            }

            return $this->find($type->id);
        });
    }

    public function stop(int $id, User $actor, string $reason, ?string $internalNote): EventType
    {
        $this->rows->setActive(EventType::class, $id, false, $actor, $reason, $internalNote, 'catalog_event_type');

        return $this->find($id);
    }

    public function activate(int $id, User $actor, ?string $reason): EventType
    {
        $this->rows->setActive(EventType::class, $id, true, $actor, $reason, null, 'catalog_event_type');

        return $this->find($id);
    }

    public function move(int $id, string $direction, User $actor): EventType
    {
        $this->rows->move($this->find($id), EventType::query(), $direction, $actor, 'catalog_event_type_moved');

        return $this->find($id);
    }

    private function query(): Builder
    {
        return EventType::query()->withCount('serviceRequests as requests_count');
    }
}
