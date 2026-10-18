<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\PosTerminal;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Layer 1 Input Guard — backend tidak boleh mempercayai input frontend.
 */
class GuardInputTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create(['role' => 'kasir', 'shift_status' => 'open']);
        $this->kasir->assignRole('kasir');
    }

    private function makeVariant(int $stock = 10, int $price = 10000): ProductVariant
    {
        $product = Product::factory()->create(['base_price' => $price, 'base_cost' => 5000]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => $price,
            'cost' => 5000,
            'stock' => $stock,
        ]);
    }

    public function test_negative_qty_is_rejected(): void
    {
        $variant = $this->makeVariant(stock: 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Jumlah item tidak valid/');

        app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => -5]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 100000,
        );
    }

    public function test_zero_qty_is_rejected(): void
    {
        $variant = $this->makeVariant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Jumlah item tidak valid/');

        app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 0]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 100000,
        );
    }

    public function test_negative_qty_does_not_increase_stock(): void
    {
        $variant = $this->makeVariant(stock: 10);

        try {
            app(TransactionService::class)->processTransaction(
                user: $this->kasir,
                cart: [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => -5]],
                customerId: null,
                paymentMethod: 'cash',
                amountTendered: 100000,
            );
        } catch (RuntimeException) {
            // Diharapkan gagal.
        }

        // Stok harus tetap 10 — qty negatif tidak boleh mengubah apa pun.
        $this->assertEquals(10, $variant->fresh()->stock);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_discount_is_clamped_to_subtotal(): void
    {
        $variant = $this->makeVariant(price: 10000);

        // Diskon 999.999.999 jauh melebihi subtotal 10.000 → di-clamp ke 10.000.
        $transaction = app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 1]],
            customerId: null,
            paymentMethod: 'qris',
            amountTendered: 0,
            discount: 999999999,
        );

        $this->assertEquals(10000, $transaction->discount);
        // total = 10000 + 1100 - 10000 = 1100 (pajak tetap dihitung, tidak negatif).
        $this->assertEquals(1100, $transaction->total);
        $this->assertGreaterThan(0, $transaction->total);
    }

    public function test_unknown_payment_method_is_rejected(): void
    {
        $variant = $this->makeVariant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Metode pembayaran tidak dikenal/');

        app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 1]],
            customerId: null,
            paymentMethod: 'bitcoin',
            amountTendered: 100000,
        );
    }

    public function test_negative_amount_tendered_is_rejected(): void
    {
        $variant = $this->makeVariant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak boleh negatif/');

        app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 1]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: -1,
        );
    }

    public function test_cart_property_is_locked_from_client_tampering(): void
    {
        // #[Locked] → Livewire melempar CannotUpdateLockedPropertyException
        // saat klien mencoba mengubah $cart langsung lewat request.
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->set('cart', [$this->makeVariant()->id => ['id' => 1, 'name' => 'Evil', 'price' => 1, 'qty' => -100]]);
    }
}
