<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    public function definition(): array
    {
        $word = fake()->unique()->word();

        return ['name' => ['en' => "Category {$word}", 'ar' => "تصنيف {$word}"], 'is_active' => true, 'sort' => 0];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
