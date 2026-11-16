<?php

namespace App\Services\Store;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read side of the admin-run store for the client app (Slice 9, `slice-9-contract.md` §4). Public; read-only.
 */
class ProductCatalog
{
    /**
     * Active store categories, in admin order.
     *
     * @return Collection<int, ProductCategory>
     */
    public function categories(): Collection
    {
        return ProductCategory::query()->where('is_active', true)->with('image')->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * Visible products, optionally of one category and matching a text (name, ar or en).
     */
    public function products(?int $categoryId, ?string $q, int $perPage): LengthAwarePaginator
    {
        return Product::query()
            ->visible()
            ->when($categoryId, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->when($q, function (Builder $query, string $text) {
                $like = '%'.addcslashes($text, '\\%_').'%';
                // JSON text comes back with a binary collation; compare case-insensitively ("gift" finds "Gift box").
                $query->where(fn (Builder $name) => $name
                    ->whereRaw("json_unquote(json_extract(products.name, '$.ar')) collate utf8mb4_unicode_ci like ?", [$like])
                    ->orWhereRaw("json_unquote(json_extract(products.name, '$.en')) collate utf8mb4_unicode_ci like ?", [$like]));
            })
            ->with(['image', 'variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('id')])
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * One visible product with its active variants, or null (→ 404).
     */
    public function product(int $id): ?Product
    {
        return Product::query()
            ->visible()
            ->with(['image', 'category', 'variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('id')])
            ->find($id);
    }
}
