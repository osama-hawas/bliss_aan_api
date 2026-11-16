<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'TEST-'.fake()->unique()->numerify('######'),
            'attributes' => null,
            'price' => '100.00',
            'stock' => 10,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function stock(int $stock): static
    {
        return $this->state(['stock' => $stock]);
    }
}
