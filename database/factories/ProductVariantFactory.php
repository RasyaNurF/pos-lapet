<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['1kg', '500gr', '5kg', 'pcs']).' '.fake()->unique()->numberBetween(1, 999),
            'sku' => 'VAR-'.fake()->unique()->numerify('######'),
            'price' => fake()->numberBetween(1000, 500000),
            'cost' => fake()->numberBetween(500, 400000),
            'stock' => fake()->numberBetween(0, 100),
        ];
    }
}
