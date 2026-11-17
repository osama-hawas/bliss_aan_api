<?php

namespace App\Services\Catalog\Admin;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service categories in the dashboard (Req 10, 55; docs/slice-16-contract.md §4.1). Stopping a category hides every
 * service in it through the existing readers (D34); service rows and provider rows are not touched (D180).
 */
class CatalogCategories
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
        CatalogRows::search($query, $filters['q'], ['name', 'description']);
        $query->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'));

        return CatalogRows::sort($query, $sort, 'service_categories')->paginate($perPage);
    }

    public function find(int $id): ServiceCategory
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @param  array{name?: array, description?: ?array, image_media_id?: ?int}  $data
     */
    public function create(array $data, User $actor): ServiceCategory
    {
        return DB::transaction(function () use ($data, $actor) {
            $category = new ServiceCategory([
                'is_active' => true,
                'sort' => $this->rows->nextSort(ServiceCategory::query()),
            ]);
            $this->rows->fillText($category, $data, ['name', 'description']);
            if (array_key_exists('image_media_id', $data)) {
                $this->rows->setImage($category, $actor, $data['image_media_id']);
            }
            $this->audit->log('catalog_category_created', $actor, $category, null, null, $this->values($category));

            return $this->find($category->id);
        });
    }

    /**
     * @param  array{name?: array, description?: ?array, image_media_id?: ?int}  $data
     */
    public function update(int $id, array $data, User $actor): ServiceCategory
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $category = ServiceCategory::query()->lockForUpdate()->findOrFail($id);
            $before = $this->values($category);

            $this->rows->fillText($category, $data, ['name', 'description']);
            if (array_key_exists('image_media_id', $data)) {
                $this->rows->setImage($category, $actor, $data['image_media_id']);
            }

            $after = $this->values($category);
            [$old, $new] = self::diff($before, $after);
            if ($new !== []) {
                $this->audit->log('catalog_category_updated', $actor, $category, null, $old, $new);
            }

            return $this->find($category->id);
        });
    }

    public function stop(int $id, User $actor, string $reason, ?string $internalNote): ServiceCategory
    {
        $this->rows->setActive(ServiceCategory::class, $id, false, $actor, $reason, $internalNote, 'catalog_category');

        return $this->find($id);
    }

    public function activate(int $id, User $actor, ?string $reason): ServiceCategory
    {
        $this->rows->setActive(ServiceCategory::class, $id, true, $actor, $reason, null, 'catalog_category');

        return $this->find($id);
    }

    public function move(int $id, string $direction, User $actor): ServiceCategory
    {
        $this->rows->move($this->find($id), ServiceCategory::query(), $direction, $actor, 'catalog_category_moved');

        return $this->find($id);
    }

    /**
     * Active services of the category, for the stop impact preview.
     *
     * @return list<int>
     */
    public function activeServiceIds(int $id): array
    {
        $this->find($id);

        return Service::query()->where('category_id', $id)->where('is_active', true)->pluck('id')->all();
    }

    private function query(): Builder
    {
        return ServiceCategory::query()
            ->with('image')
            ->withCount([
                'services',
                'services as active_services_count' => fn (Builder $q) => $q->where('is_active', true),
            ]);
    }

    private function values(ServiceCategory $category): array
    {
        return [
            'name' => $category->getTranslations('name'),
            'description' => $category->getTranslations('description') ?: null,
            'image_media_id' => $this->rows->imageId($category),
        ];
    }

    /**
     * Changed keys only, as [old, new].
     *
     * @return array{0: array, 1: array}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }
}
