<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.fake()->unique()->numerify('######'),
            'name' => fake()->unique()->words(3, true),
            'category_id' => Category::factory(),
            'base_price' => fake()->numberBetween(1000, 500000),
            'base_cost' => fake()->numberBetween(500, 400000),
            'stock_alert' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
