<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\PosTerminal;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SuspendedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PosTerminalTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create(['role' => 'kasir', 'shift_status' => 'open']);
        $this->kasir->assignRole('kasir');

        $product = Product::factory()->create(['base_price' => 10000, 'base_cost' => 5000]);
        $this->variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'BARCODE-001',
            'price' => 10000,
            'cost' => 5000,
            'stock' => 10,
        ]);
    }

    public function test_add_to_cart_updates_subtotal_and_total(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)
            ->call('addToCart', $this->variant->id)
            ->assertSet('cart', fn (array $cart): bool => $cart[$this->variant->id]['qty'] === 2)
            ->assertSet('subtotal', 20000)
            ->assertSet('tax', 2200) // round(20000 * 0.11)
            ->assertSet('total', 22200);
    }

    public function test_decrease_qty_removes_item_at_zero(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)
            ->call('decreaseQty', $this->variant->id)
            ->assertSet('cart', fn (array $cart): bool => $cart === [])
            ->assertSet('subtotal', 0);
    }

    public function test_cannot_add_more_than_stock(): void
    {
        $this->variant->update(['stock' => 2]);

        $component = Livewire::actingAs($this->kasir)->test(PosTerminal::class);

        $component->call('addToCart', $this->variant->id);
        $component->call('addToCart', $this->variant->id);

        // Pada qty = 2 (stok habis) → dispatch swal:error, cart tidak bertambah.
        $component->call('addToCart', $this->variant->id)
            ->assertSet('cart', fn (array $cart): bool => $cart[$this->variant->id]['qty'] === 2)
            ->assertDispatched('swal:error');
    }

    public function test_add_by_barcode_finds_variant_by_sku(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addByBarcode', 'BARCODE-001')
            ->assertSet('cart', fn (array $cart): bool => isset($cart[$this->variant->id]));
    }

    public function test_checkout_via_component_reduces_stock(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)
            ->call('quickTender', 50000)
            ->call('checkout')
            ->assertDispatched('swal:success')
            ->assertDispatched('print-receipt')
            ->assertSet('cart', fn (array $cart): bool => $cart === []);

        $this->assertEquals(9, $this->variant->fresh()->stock);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_checkout_with_insufficient_tender_fails(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)
            ->call('quickTender', 1000) // kurang dari total 11000
            ->call('checkout')
            ->assertHasErrors(['amount_tendered']);

        $this->assertEquals(10, $this->variant->fresh()->stock);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_qris_auto_sets_amount_tendered_to_total(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)
            ->set('payment_method', 'qris')
            ->assertSet('amount_tendered', 11100); // total = 10000 + pajak 1100
    }

    public function test_qris_checkout_succeeds_without_manual_tender(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)
            ->set('payment_method', 'qris')
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertDispatched('swal:success')
            ->assertDispatched('print-receipt')
            ->assertSet('cart', fn (array $cart): bool => $cart === []);

        $this->assertEquals(9, $this->variant->fresh()->stock);

        $transaction = Transaction::first();
        $this->assertEquals(11100, $transaction->total);
        $this->assertEquals('qris', $transaction->payments->first()->method);
    }

    public function test_suspend_and_resume_cart(): void
    {
        $component = Livewire::actingAs($this->kasir)->test(PosTerminal::class);

        $component->call('addToCart', $this->variant->id)
            ->call('suspendTransaction')
            ->assertSet('cart', fn (array $cart): bool => $cart === []);

        $this->assertDatabaseCount('suspended_transactions', 1);

        $hold = SuspendedTransaction::first();

        $component->call('resumeTransaction', $hold->id)
            ->assertSet('cart', fn (array $cart): bool => isset($cart[$this->variant->id]));

        $this->assertDatabaseCount('suspended_transactions', 0);
    }

    public function test_reset_cart_clears_everything(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $this->variant->id)
            ->call('resetCart')
            ->assertSet('cart', fn (array $cart): bool => $cart === [])
            ->assertSet('discount', 0)
            ->assertSet('customer_id', null);
    }
}
