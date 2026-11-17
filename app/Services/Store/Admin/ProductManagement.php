<?php

namespace App\Services\Store\Admin;

use App\Enums\ErrorCode;
use App\Enums\StockMovementSource;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\AppSettings;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Store products and their variants in the dashboard (Slice 18, `slice-18-contract.md` §5.2–§5.3). `products.manage`.
 *
 * - Nothing is deleted (D276): products and variants are stopped / activated. Carts keep their lines flagged
 *   unavailable (D205); orders are snapshots and never change.
 * - Stock changes only through `StockAdjustments` (D274); a new variant's stock is its `initial` movement.
 * - `base_price` is kept by the server = lowest active variant price (else lowest of all) (D275).
 * - Lock order: product → its variants. Every write is audit-logged with the product as subject, so the product's
 *   history is one query.
 */
class ProductManagement
{
    public const SORTS = ['created_at', '-created_at', 'sort', '-sort'];

    public const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StoreImages $images,
        private readonly StockAdjustments $stock,
        private readonly AppSettings $settings,
    ) {}

    /**
     * The low-stock level (`store.low_stock_threshold`, D278), or null when not configured.
     */
    public function lowStockThreshold(): ?int
    {
        $value = $this->settings->get('store.low_stock_threshold');

        return $value === null ? null : (int) $value;
    }

    /**
     * @param  array{q: ?string, category_id: ?int, status: ?string, stock: ?string, low_stock: bool}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';
        $threshold = null;
        if ($filters['low_stock']) {
            $threshold = $this->settings->required('store.low_stock_threshold');
        }

        return Product::query()
            ->with(['image', 'category'])
            ->withCount(['variants', 'variants as active_variants_count' => fn (Builder $q) => $q->where('is_active', true)])
            ->withSum(['variants as active_stock' => fn (Builder $q) => $q->where('is_active', true)], 'stock')
            ->withMin(['variants as price_min' => fn (Builder $q) => $q->where('is_active', true)], 'price')
            ->withMin(['variants as active_stock_min' => fn (Builder $q) => $q->where('is_active', true)], 'stock')
            ->when($filters['q'], function (Builder $q, string $text) {
                $like = '%'.addcslashes($text, '\\%_').'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("json_unquote(json_extract(products.name, '$.ar')) collate utf8mb4_unicode_ci like ?", [$like])
                    ->orWhereRaw("json_unquote(json_extract(products.name, '$.en')) collate utf8mb4_unicode_ci like ?", [$like])
                    ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', $like)));
            })
            ->when($filters['category_id'], fn (Builder $q, int $id) => $q->where('category_id', $id))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->when($filters['stock'] === 'in_stock', fn (Builder $q) => $q->whereHas('variants', fn (Builder $v) => $v->where('is_active', true)->where('stock', '>', 0)))
            ->when($filters['stock'] === 'out_of_stock', fn (Builder $q) => $q->whereDoesntHave('variants', fn (Builder $v) => $v->where('is_active', true)->where('stock', '>', 0)))
            ->when($threshold !== null, fn (Builder $q) => $q->whereHas('variants', fn (Builder $v) => $v->where('is_active', true)->where('stock', '<=', (int) $threshold)))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): Product
    {
        return Product::query()
            ->with(['image', 'category', 'variants' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);
    }

    /**
     * The latest audit rows of a product and its variants (D279), newest first.
     *
     * @return Collection<int, AuditLog>
     */
    public function history(Product $product): Collection
    {
        return AuditLog::query()
            ->where('subject_type', $product->getMorphClass())
            ->where('subject_id', $product->id)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }

    /**
     * @param  array{category_id: int, name: array, description?: ?array, image_media_id?: ?int, sort?: int, variants: list<array>}  $data
     */
    public function create(array $data, User $actor): Product
    {
        $skus = array_map(fn (array $v) => strtoupper(trim($v['sku'])), $data['variants']);

        return $this->guardSkuRace($skus, null, null, fn () => DB::transaction(function () use ($data, $actor) {
            $this->assertCategory($data['category_id']);
            $variants = array_map(fn (array $v) => $this->normalizeVariant($v), $data['variants']);
            $this->assertVariantsDistinct($variants);
            $this->assertSkusFree(array_column($variants, 'sku'));

            $product = new Product(array_filter([
                'category_id' => $data['category_id'],
                'name' => $data['name'],
                'description' => $this->description($data['description'] ?? null),
                'base_price' => min(array_column($variants, 'price')),
                'is_active' => true,
            ], fn ($value) => $value !== null));
            if (array_key_exists('sort', $data)) {
                $product->forceFill(['sort' => (int) $data['sort']]); // column from S9b (D209)
            }
            $product->save();

            foreach ($variants as $variant) {
                $row = $product->variants()->create([...$variant, 'is_active' => true]);
                if ($row->stock > 0) {
                    $this->stock->record($row, 0, StockMovementSource::Initial, $actor);
                }
            }
            $this->images->apply($product, array_key_exists('image_media_id', $data), $data['image_media_id'] ?? null, $actor);
            $this->audit->log('product.created', $actor, $product, null, null, [
                'name' => $data['name'],
                'category_id' => $product->category_id,
                'variants' => array_map(fn (array $v) => ['sku' => $v['sku'], 'price' => $v['price'], 'stock' => $v['stock']], $variants),
            ]);

            return $this->find($product->id);
        }));
    }

    /**
     * @param  array{category_id?: int, name?: array, description?: ?array, image_media_id?: ?int, sort?: int}  $data
     */
    public function update(int $id, array $data, User $actor): Product
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            $old = $new = [];

            if (isset($data['category_id']) && $data['category_id'] !== $product->category_id) {
                $this->assertCategory($data['category_id']);
                [$old['category_id'], $new['category_id']] = [$product->category_id, $data['category_id']];
                $product->category_id = $data['category_id'];
            }
            if (isset($data['name']) && $data['name'] !== $product->getTranslations('name')) {
                [$old['name'], $new['name']] = [$product->getTranslations('name'), $data['name']];
                $product->setTranslations('name', $data['name']);
            }
            if (array_key_exists('description', $data)) {
                $description = $this->description($data['description']);
                $current = $product->getTranslations('description') ?: null;
                if ($description !== $current) {
                    [$old['description'], $new['description']] = [$current, $description];
                    $description === null
                        ? $product->setRawAttributes([...$product->getAttributes(), 'description' => null])
                        : $product->setTranslations('description', $description);
                }
            }
            if (array_key_exists('sort', $data) && (int) $data['sort'] !== (int) $product->getAttribute('sort')) {
                [$old['sort'], $new['sort']] = [$product->getAttribute('sort'), (int) $data['sort']];
                $product->forceFill(['sort' => (int) $data['sort']]); // column from S9b (D209)
            }
            $product->save();

            if ($image = $this->images->apply($product, array_key_exists('image_media_id', $data), $data['image_media_id'] ?? null, $actor)) {
                [$old['image_media_id'], $new['image_media_id']] = [$image['old'], $image['new']];
            }
            if ($new) {
                $this->audit->log('product.updated', $actor, $product, null, $old, $new);
            }

            return $this->find($product->id);
        });
    }

    public function setActive(int $id, bool $active, User $actor, ?string $reason, ?string $note): Product
    {
        return DB::transaction(function () use ($id, $active, $actor, $reason, $note) {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            if ($product->is_active === $active) {
                throw new ApiException(ErrorCode::InvalidState, __($active ? 'store.admin.already_active' : 'store.admin.already_stopped'));
            }
            $product->update(['is_active' => $active]);
            $this->audit->log($active ? 'product.activated' : 'product.stopped', $actor, $product, $reason,
                ['is_active' => ! $active], array_filter(['is_active' => $active, 'internal_note' => $note], fn ($v) => $v !== null));

            return $this->find($product->id);
        });
    }

    /**
     * @param  array{sku: string, attributes?: ?array, price: string|int|float, stock: int}  $data
     */
    public function addVariant(int $productId, array $data, User $actor): Product
    {
        return $this->guardSkuRace([strtoupper(trim($data['sku']))], null, 'sku', fn () => DB::transaction(function () use ($productId, $data, $actor) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $variant = $this->normalizeVariant($data);
            $siblings = $product->variants()->lockForUpdate()->get();
            $this->assertVariantsDistinct([...$siblings->where('is_active', true)->map(fn (ProductVariant $v) => ['attributes' => $v->getAttribute('attributes')])->all(), $variant], 'attributes');
            $this->assertSkusFree([$variant['sku']], null, 'sku');

            $row = $product->variants()->create([...$variant, 'is_active' => true]);
            if ($row->stock > 0) {
                $this->stock->record($row, 0, StockMovementSource::Initial, $actor);
            }
            $this->syncBasePrice($product);
            $this->audit->log('product_variant.created', $actor, $product, null, null,
                ['variant_id' => $row->id, 'sku' => $row->sku, 'attributes' => $row->getAttribute('attributes'), 'price' => $row->price, 'stock' => $row->stock]);

            return $this->find($product->id);
        }));
    }

    /**
     * SKU, attributes and price only; stock goes through `StockAdjustments` (D274). A price change reaches carts at
     * once (S9b shows the change notice, D210) and never touches orders (snapshots, D281).
     *
     * @param  array{sku?: string, attributes?: ?array, price?: string|int|float}  $data
     */
    public function updateVariant(int $variantId, array $data, User $actor): Product
    {
        $skus = isset($data['sku']) ? [strtoupper(trim($data['sku']))] : [];

        return $this->guardSkuRace($skus, $variantId, 'sku', fn () => DB::transaction(function () use ($variantId, $data, $actor) {
            $productId = ProductVariant::query()->whereKey($variantId)->value('product_id') ?? throw new ApiException(ErrorCode::NotFound);
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);
            $old = $new = [];

            if (isset($data['sku'])) {
                $sku = strtoupper(trim($data['sku']));
                if ($sku !== $variant->sku) {
                    $this->assertSkusFree([$sku], $variant->id, 'sku');
                    [$old['sku'], $new['sku']] = [$variant->sku, $sku];
                    $variant->sku = $sku;
                }
            }
            if (array_key_exists('attributes', $data)) {
                $attributes = $this->attributes($data['attributes']);
                if ($attributes !== $variant->getAttribute('attributes')) {
                    if ($variant->is_active) {
                        $siblings = $product->variants()->whereKeyNot($variant->id)->where('is_active', true)->get();
                        $this->assertVariantsDistinct([...$siblings->map(fn (ProductVariant $v) => ['attributes' => $v->getAttribute('attributes')])->all(), ['attributes' => $attributes]], 'attributes');
                    }
                    [$old['attributes'], $new['attributes']] = [$variant->getAttribute('attributes'), $attributes];
                    $variant->setAttribute('attributes', $attributes);
                }
            }
            if (isset($data['price'])) {
                $price = number_format((float) $data['price'], 2, '.', '');
                if ($price !== $variant->price) {
                    [$old['price'], $new['price']] = [$variant->price, $price];
                    $variant->price = $price;
                }
            }
            $variant->save();

            if ($new) {
                $this->syncBasePrice($product);
                $this->audit->log('product_variant.updated', $actor, $product, null,
                    ['variant_id' => $variant->id, ...$old], ['variant_id' => $variant->id, ...$new]);
            }

            return $this->find($product->id);
        }));
    }

    public function setVariantActive(int $variantId, bool $active, User $actor, ?string $reason): Product
    {
        return DB::transaction(function () use ($variantId, $active, $actor, $reason) {
            $productId = ProductVariant::query()->whereKey($variantId)->value('product_id') ?? throw new ApiException(ErrorCode::NotFound);
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);
            if ($variant->is_active === $active) {
                throw new ApiException(ErrorCode::InvalidState, __($active ? 'store.admin.already_active' : 'store.admin.already_stopped'));
            }
            if ($active) {
                $siblings = $product->variants()->whereKeyNot($variant->id)->where('is_active', true)->get();
                $this->assertVariantsDistinct([...$siblings->map(fn (ProductVariant $v) => ['attributes' => $v->getAttribute('attributes')])->all(), ['attributes' => $variant->getAttribute('attributes')]], 'attributes');
            }
            $variant->update(['is_active' => $active]);
            $this->syncBasePrice($product);
            $this->audit->log($active ? 'product_variant.activated' : 'product_variant.stopped', $actor, $product, $reason,
                ['variant_id' => $variant->id, 'sku' => $variant->sku, 'is_active' => ! $active], ['variant_id' => $variant->id, 'sku' => $variant->sku, 'is_active' => $active]);

            return $this->find($product->id);
        });
    }

    /**
     * D275: `base_price` = lowest active variant price, else the lowest of all its variants. Product row is locked.
     */
    private function syncBasePrice(Product $product): void
    {
        $price = $product->variants()->where('is_active', true)->min('price') ?? $product->variants()->min('price');
        if ($price !== null && (string) $price !== (string) $product->base_price) {
            $product->update(['base_price' => $price]);
        }
    }

    private function assertCategory(int $id): void
    {
        if (! ProductCategory::query()->whereKey($id)->exists()) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['category_id' => [__('validation.exists', ['attribute' => 'category_id'])]]);
        }
    }

    /**
     * @return array{sku: string, attributes: ?array, price: string, stock: int}
     */
    private function normalizeVariant(array $data): array
    {
        return [
            'sku' => strtoupper(trim($data['sku'])),
            'attributes' => $this->attributes($data['attributes'] ?? null),
            'price' => number_format((float) $data['price'], 2, '.', ''),
            'stock' => (int) ($data['stock'] ?? 0),
        ];
    }

    /**
     * Keeps only the given attribute codes (size / color) with trimmed {ar, en}; none → null.
     */
    private function attributes(?array $attributes): ?array
    {
        $out = [];
        foreach ($attributes ?? [] as $code => $value) {
            if (is_array($value) && filled($value['ar'] ?? null) && filled($value['en'] ?? null)) {
                $out[$code] = ['ar' => trim($value['ar']), 'en' => trim($value['en'])];
            }
        }
        ksort($out);

        return $out ?: null;
    }

    /**
     * Two active variants of one product cannot have the same options (case-insensitive) (D273).
     *
     * @param  list<array{attributes: ?array}>  $variants
     */
    private function assertVariantsDistinct(array $variants, ?string $field = null): void
    {
        $seen = [];
        foreach ($variants as $i => $variant) {
            $attributes = $variant['attributes'] ?? [];
            ksort($attributes);
            $key = mb_strtolower(json_encode($attributes, JSON_UNESCAPED_UNICODE));
            if (isset($seen[$key])) {
                throw new ApiException(ErrorCode::ValidationFailed, null, [($field ?? "variants.{$i}.attributes") => [__('store.admin.variant_duplicate')]]);
            }
            $seen[$key] = true;
        }
    }

    /**
     * L2: two staff saving the same SKU at once both pass the pre-check; the unique index stops the second insert.
     * Map that to the same 422 field error as the pre-check instead of a 500.
     *
     * @param  list<string>  $skus
     * @param  Closure(): Product  $work
     */
    private function guardSkuRace(array $skus, ?int $exceptVariantId, ?string $field, Closure $work): Product
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException $e) {
            if ($skus === []) {
                throw $e;
            }
            $this->assertSkusFree($skus, $exceptVariantId, $field); // the other transaction has committed: names the field
            throw new ApiException(ErrorCode::ValidationFailed, null, [($field ?? 'variants.0.sku') => [__('store.admin.sku_taken')]]);
        }
    }

    /**
     * @param  list<string>  $skus  already upper-case
     */
    private function assertSkusFree(array $skus, ?int $exceptVariantId = null, ?string $field = null): void
    {
        $errors = [];
        foreach (array_count_values($skus) as $sku => $count) {
            if ($count > 1) {
                // array_count_values() turns numeric SKUs ("12345") into int keys: compare as strings (L3).
                $errors[$field ?? 'variants.'.array_search((string) $sku, $skus, true).'.sku'] = [__('store.admin.sku_taken')];
            }
        }
        $taken = ProductVariant::query()->whereIn('sku', $skus)
            ->when($exceptVariantId, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->pluck('sku')->map(fn (string $s) => strtoupper($s))->all();
        foreach ($taken as $sku) {
            $errors[$field ?? 'variants.'.array_search((string) $sku, $skus, true).'.sku'] = [__('store.admin.sku_taken')];
        }
        if ($errors) {
            throw new ApiException(ErrorCode::ValidationFailed, null, $errors);
        }
    }

    private function description(?array $description): ?array
    {
        $description = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : null, $description ?? []), 'filled');

        return $description ?: null;
    }
}
