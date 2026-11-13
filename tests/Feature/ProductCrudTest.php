<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');
    }

    public function test_admin_can_view_product_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.index'))
            ->assertOk();
    }

    public function test_admin_can_create_product_with_variant(): void
    {
        $category = Category::factory()->create();

        $response = $this->actingAs($this->admin)->post(route('products.store'), [
            'sku' => 'SKU-TEST-001',
            'name' => 'Makanan Kucing',
            'category_id' => $category->id,
            'base_price' => 50000,
            'base_cost' => 30000,
            'stock_alert' => 5,
            'is_active' => '1',
            'variant_name' => '1kg',
            'variant_stock' => 10,
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('products', ['sku' => 'SKU-TEST-001', 'name' => 'Makanan Kucing']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'SKU-TEST-001-V1', 'stock' => 10, 'price' => 50000]);
    }

    public function test_admin_can_update_product(): void
    {
        $product = Product::factory()->create();

        $response = $this->actingAs($this->admin)->put(route('products.update', $product), [
            'sku' => $product->sku,
            'name' => 'Nama Baru',
            'category_id' => null,
            'base_price' => 75000,
            'base_cost' => 40000,
            'stock_alert' => 3,
            'is_active' => '1',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Nama Baru']);
    }

    public function test_admin_can_delete_product_soft(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('products.destroy', $product))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('products.index'))->assertRedirect(route('login'));
    }

    public function test_kasir_cannot_create_product(): void
    {
        $kasir = User::factory()->create(['role' => 'kasir']);
        $kasir->assignRole('kasir');

        $this->actingAs($kasir)
            ->post(route('products.store'), [
                'sku' => 'X-1',
                'name' => 'Test',
                'base_price' => 1000,
                'base_cost' => 500,
                'stock_alert' => 1,
            ])
            ->assertForbidden();
    }

    public function test_editing_variant_prices_preserves_inactive_status_and_other_products(): void
    {
        $product = Product::factory()->create(['is_active' => false]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 10000]);
        $other = ProductVariant::factory()->create(['price' => 50000]);
        $this->actingAs($this->admin)->put(route('products.update', $product), [
            ...$product->only(['sku', 'name', 'category_id', 'base_price', 'base_cost', 'stock_alert']),
            'is_active' => '0',
            'variants' => [
                $variant->id => ['price' => 25000, 'cost' => 12000],
                $other->id => ['price' => 1, 'cost' => 1],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(25000, $variant->fresh()->price);
        $this->assertSame(12000, $variant->fresh()->cost);
        $this->assertSame(50000, $other->fresh()->price);
    }
}
