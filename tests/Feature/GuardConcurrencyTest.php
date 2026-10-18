<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\PosTerminal;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Layer 4 Data Guard — idempotency & concurrency.
 */
class GuardConcurrencyTest extends TestCase
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

    // ------------------------------------------------------------------
    // Idempotency checkout
    // ------------------------------------------------------------------

    public function test_same_client_token_processes_transaction_only_once(): void
    {
        $variant = $this->makeVariant(stock: 10);
        $token = 'tok-'.uniqid();
        $cart = [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 2]];

        $service = app(TransactionService::class);

        $first = $service->processTransaction(
            user: $this->kasir,
            cart: $cart,
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 100000,
            clientToken: $token,
        );

        // Klik bayar kedua dengan token yang sama → transaksi yang sama dikembalikan.
        $second = $service->processTransaction(
            user: $this->kasir,
            cart: $cart,
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 100000,
            clientToken: $token,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertEquals(1, Transaction::count());

        // Stok hanya terpotong sekali (10 - 2 = 8), bukan dua kali.
        $this->assertEquals(8, $variant->fresh()->stock);
    }

    public function test_double_tap_on_component_creates_single_transaction(): void
    {
        $variant = $this->makeVariant(stock: 10);

        $component = Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->set('amount_tendered', 100000)
            ->call('openPaymentPanel')
            ->assertSet('showPaymentPanel', true);

        // "Bayar" dua kali pada sesi yang sama.
        $component->call('checkout');
        $component->call('checkout');

        $this->assertEquals(1, Transaction::count());
        $this->assertEquals(9, $variant->fresh()->stock);
    }

    public function test_different_tokens_create_separate_transactions(): void
    {
        $variant = $this->makeVariant(stock: 10);
        $service = app(TransactionService::class);
        $cart = [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 1]];

        $service->processTransaction($this->kasir, $cart, null, 'cash', 100000, 0, 'token-a');
        $service->processTransaction($this->kasir, $cart, null, 'cash', 100000, 0, 'token-b');

        $this->assertEquals(2, Transaction::count());
        $this->assertEquals(8, $variant->fresh()->stock);
    }

    // ------------------------------------------------------------------
    // Invoice counter
    // ------------------------------------------------------------------

    public function test_invoice_numbers_are_sequential_without_duplicates(): void
    {
        $variant = $this->makeVariant(stock: 50);
        $service = app(TransactionService::class);
        $cart = [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 1]];

        $numbers = [];
        foreach (range(1, 5) as $i) {
            $numbers[] = $service->processTransaction(
                $this->kasir, $cart, null, 'cash', 100000, 0, 'inv-token-'.$i
            )->invoice_no;
        }

        $this->assertSame($numbers, array_unique($numbers), 'Invoice tidak boleh duplikat.');
        $this->assertEquals(5, Transaction::count());

        // Berurutan: suffix 0001..0005.
        $suffixes = array_map(fn (string $n): int => (int) substr($n, -4), $numbers);
        $this->assertSame([1, 2, 3, 4, 5], $suffixes);
    }

    public function test_invoice_counter_is_persisted_per_day(): void
    {
        $variant = $this->makeVariant(stock: 20);
        $service = app(TransactionService::class);
        $cart = [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 1]];

        $service->processTransaction($this->kasir, $cart, null, 'cash', 100000, 0, 'c1');
        $service->processTransaction($this->kasir, $cart, null, 'cash', 100000, 0, 'c2');

        $this->assertDatabaseHas('invoice_counters', [
            'day' => now()->format('Ymd'),
            'sequence' => 2,
        ]);
    }

    // ------------------------------------------------------------------
    // Void ganda
    // ------------------------------------------------------------------

    public function test_void_twice_does_not_restore_stock_twice(): void
    {
        $variant = $this->makeVariant(stock: 10);
        $service = app(TransactionService::class);
        $cart = [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 3]];

        $transaction = $service->processTransaction($this->kasir, $cart, null, 'cash', 100000, 0, 'v1');
        $this->assertEquals(7, $variant->fresh()->stock);

        $service->voidTransaction($transaction, $this->kasir, 'uji');
        $this->assertEquals(10, $variant->fresh()->stock);

        try {
            $service->voidTransaction($transaction->fresh(), $this->kasir, 'lagi');
            $this->fail('Void kedua seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pernah di-void', $e->getMessage());
        }

        // Stok tidak terkembalikan dua kali.
        $this->assertEquals(10, $variant->fresh()->stock);
    }

    // ------------------------------------------------------------------
    // Shift ganda
    // ------------------------------------------------------------------

    public function test_open_shift_twice_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'kasir', 'shift_status' => 'closed']);
        $user->assignRole('kasir');

        $service = app(ShiftService::class);

        $service->openShift($user, 100000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Shift sudah terbuka/');

        // Cek ulang dari DB (state fresh) — tetap ditolak.
        $service->openShift($user->fresh(), 100000);
    }

    public function test_close_shift_twice_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'kasir', 'shift_status' => 'closed']);
        $user->assignRole('kasir');

        $service = app(ShiftService::class);
        $service->openShift($user->fresh(), 50000);

        $service->closeShift($user->fresh(), 50000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tidak ada shift terbuka/');

        $service->closeShift($user->fresh(), 50000);
    }
}
