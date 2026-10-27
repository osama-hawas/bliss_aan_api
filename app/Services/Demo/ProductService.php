<?php

namespace App\Services\Demo;

use App\Models\DemoProduct;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductService
{
    /**
     * Get all products (paginated).
     */
    public function getAll(int $perPage = 15): LengthAwarePaginator
    {
        return DemoProduct::orderBy('id', 'desc')->paginate($perPage);
    }

    /**
     * Find product by ID.
     */
    public function find(int $id): DemoProduct
    {
        return DemoProduct::findOrFail($id);
    }

    /**
     * Store new product.
     */
    public function store(array $data): DemoProduct
    {
        return DemoProduct::create($data);
    }

    /**
     * Update product.
     */
    public function update(int $id, array $data): DemoProduct
    {
        $product = $this->find($id);
        $product->update($data);
        return $product;
    }

    /**
     * Delete product.
     */
    public function delete(int $id): bool
    {
        $product = $this->find($id);
        return $product->delete();
    }
}
