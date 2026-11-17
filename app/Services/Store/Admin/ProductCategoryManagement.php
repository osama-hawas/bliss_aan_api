<?php

namespace App\Services\Store\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Store categories in the dashboard (Slice 18, `slice-18-contract.md` §5.1). `products.manage`.
 * Nothing is deleted: a stopped category hides its products from the apps (`Product::visible()`); carts keep their
 * lines flagged unavailable and orders keep their snapshots (D276). Every write locks the row and is audit-logged.
 */
class ProductCategoryManagement
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StoreImages $images,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query()
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $name) => $name
                ->whereRaw("json_unquote(json_extract(name, '$.ar')) collate utf8mb4_unicode_ci like ?", ['%'.addcslashes($text, '\\%_').'%'])
                ->orWhereRaw("json_unquote(json_extract(name, '$.en')) collate utf8mb4_unicode_ci like ?", ['%'.addcslashes($text, '\\%_').'%'])))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('sort')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find(int $id): ProductCategory
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @param  array{name: array{ar: string, en: string}, image_media_id?: ?int}  $data
     */
    public function create(array $data, User $actor): ProductCategory
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertUniqueName($data['name']);
            $sort = (int) ProductCategory::query()->lockForUpdate()->max('sort') + 1;
            $category = ProductCategory::query()->create(['name' => $data['name'], 'is_active' => true, 'sort' => $sort]);
            $this->images->apply($category, array_key_exists('image_media_id', $data), $data['image_media_id'] ?? null, $actor);
            $this->audit->log('product_category.created', $actor, $category, null, null, ['name' => $data['name']]);

            return $this->find($category->id);
        });
    }

    /**
     * @param  array{name?: array{ar: string, en: string}, image_media_id?: ?int}  $data
     */
    public function update(int $id, array $data, User $actor): ProductCategory
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $category = ProductCategory::query()->lockForUpdate()->findOrFail($id);
            $old = $new = [];

            if (isset($data['name']) && $data['name'] !== $category->getTranslations('name')) {
                $this->assertUniqueName($data['name'], $category->id);
                $old['name'] = $category->getTranslations('name');
                $new['name'] = $data['name'];
                $category->setTranslations('name', $data['name'])->save();
            }
            if ($image = $this->images->apply($category, array_key_exists('image_media_id', $data), $data['image_media_id'] ?? null, $actor)) {
                $old['image_media_id'] = $image['old'];
                $new['image_media_id'] = $image['new'];
            }
            if ($new) {
                $this->audit->log('product_category.updated', $actor, $category, null, $old, $new);
            }

            return $this->find($category->id);
        });
    }

    public function setActive(int $id, bool $active, User $actor, ?string $reason, ?string $note): ProductCategory
    {
        return DB::transaction(function () use ($id, $active, $actor, $reason, $note) {
            $category = ProductCategory::query()->lockForUpdate()->findOrFail($id);
            if ($category->is_active === $active) {
                throw new ApiException(ErrorCode::InvalidState, __($active ? 'store.admin.already_active' : 'store.admin.already_stopped'));
            }
            $category->update(['is_active' => $active]);
            $this->audit->log($active ? 'product_category.activated' : 'product_category.stopped', $actor, $category, $reason,
                ['is_active' => ! $active], array_filter(['is_active' => $active, 'internal_note' => $note], fn ($v) => $v !== null));

            return $this->find($category->id);
        });
    }

    /**
     * Swaps `sort` with the neighbour (D277). Equal sorts are renumbered 1..n first, under a lock on every row.
     */
    public function move(int $id, string $direction, User $actor): ProductCategory
    {
        return DB::transaction(function () use ($id, $direction, $actor) {
            $rows = ProductCategory::query()->orderBy('sort')->orderBy('id')->lockForUpdate()->get();
            $index = $rows->search(fn (ProductCategory $row) => $row->id === $id);
            if ($index === false) {
                throw new ApiException(ErrorCode::NotFound);
            }
            if ($rows->pluck('sort')->unique()->count() !== $rows->count()) {
                $rows->each(fn (ProductCategory $row, int $i) => $row->update(['sort' => $i + 1]));
            }
            $other = $rows->get($direction === 'up' ? $index - 1 : $index + 1);
            if (! $other) {
                throw new ApiException(ErrorCode::InvalidState, __('store.admin.cannot_move'));
            }
            $row = $rows->get($index);
            [$a, $b] = [$row->sort, $other->sort];
            $row->update(['sort' => $b]);
            $other->update(['sort' => $a]);
            $this->audit->log('product_category.moved', $actor, $row, null, ['sort' => $a], ['sort' => $b]);

            return $this->find($row->id);
        });
    }

    private function query(): Builder
    {
        return ProductCategory::query()
            ->with('image')
            ->withCount(['products', 'products as active_products_count' => fn (Builder $q) => $q->where('is_active', true)]);
    }

    /**
     * Two categories may not share a name in either language (case-insensitive).
     *
     * @param  array{ar: string, en: string}  $name
     */
    private function assertUniqueName(array $name, ?int $exceptId = null): void
    {
        $errors = [];
        foreach (['ar', 'en'] as $locale) {
            $taken = ProductCategory::query()
                ->when($exceptId, fn (Builder $q, int $id) => $q->whereKeyNot($id))
                ->whereRaw("json_unquote(json_extract(name, '$.{$locale}')) collate utf8mb4_unicode_ci = ?", [$name[$locale]])
                ->exists();
            if ($taken) {
                $errors["name.{$locale}"] = [__('store.admin.category_name_taken')];
            }
        }
        if ($errors) {
            throw new ApiException(ErrorCode::ValidationFailed, null, $errors);
        }
    }
}
