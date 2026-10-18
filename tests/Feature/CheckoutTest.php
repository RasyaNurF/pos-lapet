<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create(['role' => 'kasir']);
        $this->kasir->assignRole('kasir');
    }

    private function makeVariant(int $stock = 10, int $price = 10000, int $cost = 5000): ProductVariant
    {
        $product = Product::factory()->create(['base_price' => $price, 'base_cost' => $cost]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => $price,
            'cost' => $cost,
            'stock' => $stock,
        ]);
    }

    public function test_checkout_reduces_stock_and_creates_transaction(): void
    {
        $variant = $this->makeVariant(stock: 10, price: 10000);

        $service = app(TransactionService::class);

        $transaction = $service->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'Test', 'price' => 10000, 'qty' => 3]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 50000,
        );

        // Stok berkurang.
        $this->assertEquals(7, $variant->fresh()->stock);

        // Header transaksi benar (subtotal 30000, pajak 11%, total 33300).
        $this->assertEquals(30000, $transaction->subtotal);
        $this->assertEquals(3300, $transaction->tax); // round(30000 * 0.11)
        $this->assertEquals(33300, $transaction->total);
        $this->assertEquals('completed', $transaction->status);
        $this->assertStringStartsWith('INV-', $transaction->invoice_no);

        // Item snapshot tersimpan.
        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transaction->id,
            'variant_id' => $variant->id,
            'qty' => 3,
            'price' => 10000,
            'subtotal' => 30000,
        ]);

        // Pembayaran tersimpan.
        $this->assertDatabaseHas('transaction_payments', [
            'transaction_id' => $transaction->id,
            'method' => 'cash',
            'amount' => 33300,
        ]);
    }

    public function test_checkout_with_discount(): void
    {
        $variant = $this->makeVariant(price: 100000);

        $transaction = app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'Test', 'price' => 100000, 'qty' => 1]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 150000,
            discount: 10000,
        );

        // subtotal 100000, pajak 11000, total = 100000 + 11000 - 10000 = 101000
        $this->assertEquals(100000, $transaction->subtotal);
        $this->assertEquals(11000, $transaction->tax);
        $this->assertEquals(10000, $transaction->discount);
        $this->assertEquals(101000, $transaction->total);
    }

    public function test_checkout_fails_when_amount_tendered_less_than_total(): void
    {
        $variant = $this->makeVariant(price: 100000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/kurang/');

        app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'Test', 'price' => 100000, 'qty' => 1]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 50000,
        );
    }

    public function test_checkout_fails_when_stock_insufficient(): void
    {
        $variant = $this->makeVariant(stock: 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Stok/');

        app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'Test', 'price' => 10000, 'qty' => 5]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 100000,
        );
    }

    public function test_qris_payment_does_not_require_amount_tendered(): void
    {
        $variant = $this->makeVariant(price: 20000);

        $transaction = app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'Test', 'price' => 20000, 'qty' => 1]],
            customerId: null,
            paymentMethod: 'qris',
            amountTendered: 0,
        );

        $this->assertEquals(22200, $transaction->total);
        $this->assertNotNull($transaction->payments->first()->reference_no);
    }
}
