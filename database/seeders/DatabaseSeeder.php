<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@lapet.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $admin->assignRole('admin');

        $kasir = User::factory()->create([
            'name' => 'Kasir Satu',
            'email' => 'kasir@lapet.test',
            'password' => 'password',
            'role' => 'kasir',
        ]);
        $kasir->assignRole('kasir');

        // Sample master data.
        $categories = Category::factory()->count(3)->create();

        Product::factory()->count(6)->create(['category_id' => $categories->random()->id])
            ->each(function (Product $product): void {
                ProductVariant::factory()->count(2)->create([
                    'product_id' => $product->id,
                    'price' => $product->base_price,
                    'cost' => $product->base_cost,
                ]);
            });

        Customer::factory()->count(5)->create();
    }
}
