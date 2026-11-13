<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\PosTerminal;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'shift_status' => 'open']);
        $this->admin->assignRole('admin');
        $this->product = Product::factory()->create(['is_active' => true, 'name' => 'Produk Uji Nonaktif']);
        $this->variant = ProductVariant::factory()->create(['product_id' => $this->product->id, 'price' => 10000, 'stock' => 10]);
    }

    private function payload(): array
    {
        return $this->product->only(['sku', 'name', 'category_id', 'base_price', 'base_cost', 'stock_alert']);
    }

    public function test_unchecked_checkbox_deactivates_product_and_hides_it_from_pos(): void
    {
        // Browser lama menghilangkan field checkbox yang tidak dicentang.
        $this->actingAs($this->admin)->put(route('products.update', $this->product), $this->payload())
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertFalse($this->product->fresh()->is_active);
        $this->get(route('pos'))->assertOk()->assertDontSee($this->product->name);
    }

    public function test_product_can_be_turned_off_and_back_on(): void
    {
        foreach ([0, 1] as $active) {
            $this->actingAs($this->admin)->put(route('products.update', $this->product), [
                ...$this->payload(), 'is_active' => (string) $active,
            ])->assertSessionHasNoErrors();
            $this->assertSame((bool) $active, $this->product->fresh()->is_active);
        }
        $this->get(route('pos'))->assertOk()->assertSee($this->product->name);
    }

    public function test_new_product_can_be_created_inactive(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), [
            ...$this->payload(), 'sku' => 'INACTIVE-NEW', 'is_active' => '0',
            'variant_name' => 'Default', 'variant_stock' => 5,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['sku' => 'INACTIVE-NEW', 'is_active' => false]);
    }

    public function test_invalid_activation_status_does_not_change_product(): void
    {
        $this->actingAs($this->admin)->put(route('products.update', $this->product), [
            ...$this->payload(), 'is_active' => 'invalid',
        ])->assertSessionHasErrors('is_active');
        $this->assertTrue($this->product->fresh()->is_active);
    }

    public function test_inactive_product_cannot_be_added_by_card_or_barcode(): void
    {
        $this->product->update(['is_active' => false]);
        Livewire::actingAs($this->admin)->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)->assertSet('cart', [])->assertDispatched('swal:error')
            ->call('addByBarcode', $this->variant->sku)->assertSet('cart', [])->assertDispatched('swal:error');
    }

    public function test_open_pos_catalog_excludes_product_after_refresh(): void
    {
        $component = Livewire::actingAs($this->admin)->test(PosTerminal::class)->assertSee($this->product->name);
        $this->product->update(['is_active' => false]);
        $component->call('$refresh')->assertDontSee($this->product->name);
    }

    public function test_deactivation_after_adding_to_cart_blocks_checkout_without_stock_changes(): void
    {
        $component = Livewire::actingAs($this->admin)->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)->call('quickTender', 50000);
        $this->product->update(['is_active' => false]);
        $component->call('checkout')->assertDispatched('swal:error');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10, $this->variant->fresh()->stock);
    }
}
