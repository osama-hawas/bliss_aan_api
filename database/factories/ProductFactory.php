<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $word = fake()->unique()->word();

        return [
            'category_id' => ProductCategory::factory(),
            'name' => ['en' => "Product {$word}", 'ar' => "منتج {$word}"],
            'description' => ['en' => 'A test product.', 'ar' => 'منتج للاختبار.'],
            'base_price' => '100.00',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
