<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->kasir = User::factory()->create(['role' => 'kasir']);
        $this->kasir->assignRole('kasir');
    }

    private function makeTransaction(int $stock = 10, int $qty = 3, string $method = 'cash'): Transaction
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'stock' => $stock,
            'price' => 10000,
        ]);

        $transaction = app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => $variant->display_name, 'price' => 10000, 'qty' => $qty]],
            customerId: null,
            paymentMethod: $method,
            amountTendered: 100000,
        );

        return $transaction->setRelation('items', $transaction->items()->get());
    }

    public function test_void_restores_stock_and_marks_transaction(): void
    {
        $transaction = $this->makeTransaction(stock: 10, qty: 3);

        $variantId = $transaction->items->first()->variant_id;
        $this->assertEquals(7, ProductVariant::find($variantId)->stock);

        $response = $this->actingAs($this->admin)->post(route('transactions.void', $transaction), [
            'reason' => 'Salah input',
        ]);

        $response->assertSessionHas('success');

        // Stok kembali.
        $this->assertEquals(10, ProductVariant::find($variantId)->stock);

        // Status voided.
        $this->assertEquals(Transaction::STATUS_VOIDED, $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->voided_at);
    }

    public function test_void_twice_is_rejected(): void
    {
        $transaction = $this->makeTransaction();

        $this->actingAs($this->admin)
            ->post(route('transactions.void', $transaction))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->post(route('transactions.void', $transaction))
            ->assertSessionHas('error');
    }

    public function test_kasir_cannot_void_transaction(): void
    {
        $transaction = $this->makeTransaction();

        $this->actingAs($this->kasir)
            ->post(route('transactions.void', $transaction))
            ->assertForbidden();
    }

    public function test_voided_transaction_still_visible_in_history(): void
    {
        $transaction = $this->makeTransaction();

        $this->actingAs($this->admin)
            ->post(route('transactions.void', $transaction))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => Transaction::STATUS_VOIDED,
        ]);
    }

    public function test_voiding_cash_sale_after_shift_close_recalculates_reconciliation(): void
    {
        $shifts = app(ShiftService::class);
        $shift = $shifts->openShift($this->kasir, 50000);

        $cashSale = $this->makeTransaction(qty: 1, method: 'cash');
        $nonCashSale = $this->makeTransaction(qty: 1, method: 'qris');

        $shifts->closeShift($this->kasir->fresh(), 61100);
        $closedShift = $shift->fresh();
        $this->assertSame(61100, $closedShift->expected_cash);
        $this->assertSame(0, $closedShift->difference);
        $this->assertSame(22200, $closedShift->total_sales);

        app(TransactionService::class)->voidTransaction($cashSale, $this->admin);

        $closedShift->refresh();
        $this->assertSame(50000, $closedShift->expected_cash);
        $this->assertSame(11100, $closedShift->difference);
        $this->assertSame(11100, $closedShift->total_sales);
        $this->assertSame(Transaction::STATUS_COMPLETED, $nonCashSale->fresh()->status);

        app(TransactionService::class)->voidTransaction($nonCashSale, $this->admin);
        $closedShift->refresh();
        $this->assertSame(50000, $closedShift->expected_cash);
        $this->assertSame(11100, $closedShift->difference);
        $this->assertSame(0, $closedShift->total_sales);
    }
}
