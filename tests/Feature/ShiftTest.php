<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\User;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftTest extends TestCase
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

    public function test_open_shift(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('shifts.open'), ['start_cash' => 100000])
            ->assertRedirect(route('pos'))
            ->assertSessionHas('success');

        $this->assertEquals('open', $this->kasir->fresh()->shift_status);
        $this->assertDatabaseHas('shifts', ['user_id' => $this->kasir->id, 'start_cash' => 100000]);
    }

    public function test_cannot_open_shift_twice(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('shifts.open'), ['start_cash' => 50000]);

        $this->actingAs($this->kasir->fresh())
            ->post(route('shifts.open'), ['start_cash' => 50000])
            ->assertSessionHas('error');
    }

    public function test_close_shift_calculates_cash_difference(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('shifts.open'), ['start_cash' => 100000]);

        // Buat satu transaksi cash 33300 (subtotal 30000 + pajak 3300).
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 10000,
            'stock' => 10,
        ]);

        app(TransactionService::class)->processTransaction(
            user: $this->kasir->fresh(),
            cart: [['id' => $variant->id, 'name' => 'X', 'price' => 10000, 'qty' => 3]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 40000,
        );

        // Kas akhir 133300 = 100000 + 33300 → selisih 0.
        $this->actingAs($this->kasir->fresh())
            ->post(route('shifts.close'), ['end_cash' => 133300])
            ->assertSessionHas('success');

        $this->assertEquals('closed', $this->kasir->fresh()->shift_status);

        $shift = Shift::first();
        $this->assertNotNull($shift->end_time);
        $this->assertEquals(133300, $shift->end_cash);
    }

    public function test_close_shift_form_previews_sales_and_expected_cash(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('shifts.open'), ['start_cash' => 50000]);

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 10000,
            'stock' => 10,
        ]);

        foreach (['cash', 'qris'] as $method) {
            app(TransactionService::class)->processTransaction(
                user: $this->kasir->fresh(),
                cart: [['id' => $variant->id, 'qty' => 1]],
                customerId: null,
                paymentMethod: $method,
                amountTendered: 20000,
            );
        }

        $response = $this->actingAs($this->kasir->fresh())
            ->get(route('shifts.close.form'))
            ->assertOk()
            ->assertSee('Penjualan Tunai')
            ->assertSee('Penjualan Non-tunai')
            ->assertSee('Kas Seharusnya');

        $this->assertSame([
            'total_sales' => 22200,
            'cash_sales' => 11100,
            'non_cash_sales' => 11100,
            'expected_cash' => 61100,
        ], $response->viewData('summary'));
    }

    public function test_pos_redirects_when_shift_not_open(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('pos'))
            ->assertRedirect(route('shifts.open.form'));
    }
}
