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

class ShiftHistoryTest extends TestCase
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

    /**
     * Selisih kas harus tersimpan di baris shift, bukan hanya lewat flash message.
     */
    public function test_close_shift_persists_cash_difference_to_shift_row(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('shifts.open'), ['start_cash' => 100000]);

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

        // Kas akhir 130000, seharusnya 133300 → kurang 3300.
        $this->actingAs($this->kasir->fresh())
            ->post(route('shifts.close'), ['end_cash' => 130000])
            ->assertSessionHas('error');

        $shift = Shift::firstOrFail();

        $this->assertEquals(133300, $shift->expected_cash);
        $this->assertEquals(-3300, $shift->difference);
    }

    /**
     * Kasir boleh lihat shift-nya sendiri, tapi bukan selisih kas seluruh kasir.
     */
    public function test_kasir_cannot_access_shift_history(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('shifts.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_shift_history(): void
    {
        $this->makeClosedShift(startCash: 100000, endCash: 95000, difference: -5000);

        $this->actingAs($this->admin)
            ->get(route('shifts.index'))
            ->assertOk()
            ->assertViewIs('shifts.index')
            ->assertSee('Riwayat Shift')
            ->assertSee('5.000', false);
    }

    /**
     * Ringkasan harus menghitung SELURUH hasil filter, bukan cuma halaman tampil.
     * Kalau tidak di-clone sebelum paginate, angkanya akan mentok di 20.
     */
    public function test_summary_covers_all_rows_not_just_current_page(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeClosedShift(startCash: 10000, endCash: 9000, difference: -1000);
        }

        $response = $this->actingAs($this->admin)->get(route('shifts.index'));
        $response->assertOk();

        // Tabel memang dipaginasi 20 baris...
        $this->assertCount(20, $response->viewData('shifts'));

        // ...tapi ringkasan tetap mencakup seluruh 25 shift.
        $summary = $response->viewData('summary');
        $this->assertEquals(25, $summary['shifts']);
        $this->assertEquals(25, $summary['mismatched']);
        $this->assertEquals(-25000, $summary['total_difference']);
    }

    /**
     * Shift berjalan belum punya selisih — jangan dihitung bermasalah.
     */
    public function test_open_shift_is_not_counted_as_mismatched(): void
    {
        $this->makeClosedShift(startCash: 100000, endCash: 100000, difference: 0);

        Shift::create([
            'user_id' => $this->kasir->id,
            'start_time' => now(),
            'start_cash' => 50000,
            'total_sales' => 0,
        ]);

        $summary = $this->actingAs($this->admin)
            ->get(route('shifts.index'))
            ->assertOk()
            ->viewData('summary');

        $this->assertEquals(2, $summary['shifts']);
        $this->assertEquals(0, $summary['mismatched']);
        $this->assertEquals(0, $summary['total_difference']);
    }

    public function test_history_filters_by_status(): void
    {
        $this->makeClosedShift(startCash: 100000, endCash: 100000, difference: 0);

        Shift::create([
            'user_id' => $this->kasir->id,
            'start_time' => now(),
            'start_cash' => 50000,
            'total_sales' => 0,
        ]);

        $summary = $this->actingAs($this->admin)
            ->get(route('shifts.index', ['status' => 'closed']))
            ->assertOk()
            ->viewData('summary');

        $this->assertEquals(1, $summary['shifts']);
    }

    public function test_history_filters_by_selected_cashier_instead_of_admin(): void
    {
        $this->makeClosedShift(startCash: 100000, endCash: 100000, difference: 0, user: $this->admin);
        $cashierShift = $this->makeClosedShift(startCash: 200000, endCash: 200000, difference: 0);

        $response = $this->actingAs($this->admin)
            ->get(route('shifts.index', ['user_id' => $this->kasir->id]))
            ->assertOk();

        $shifts = $response->viewData('shifts');
        $this->assertSame(1, $shifts->total());
        $this->assertSame($cashierShift->id, $shifts->first()->id);
        $this->assertSame($this->kasir->id, $shifts->first()->user_id);
    }

    public function test_history_filters_by_start_date_range(): void
    {
        $recent = $this->makeClosedShift(startCash: 100000, endCash: 100000, difference: 0);
        $older = $this->makeClosedShift(startCash: 100000, endCash: 100000, difference: 0);
        Shift::query()->whereKey($older->id)->update(['start_time' => now()->subDay()]);

        $shifts = $this->actingAs($this->admin)
            ->get(route('shifts.index', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->viewData('shifts');

        $this->assertSame(1, $shifts->total());
        $this->assertSame($recent->id, $shifts->first()->id);
    }

    /**
     * Kolom `difference` harus signed — selisih kurang itu bernilai negatif.
     */
    public function test_negative_difference_is_stored(): void
    {
        $this->makeClosedShift(startCash: 200000, endCash: 150000, difference: -50000);

        $this->actingAs($this->admin)
            ->get(route('shifts.index'))
            ->assertOk()
            ->assertSee('Kurang Rp 50.000', false);
    }

    private function makeClosedShift(int $startCash, int $endCash, int $difference, ?User $user = null): Shift
    {
        return Shift::create([
            'user_id' => ($user ?? $this->kasir)->id,
            'start_time' => now()->subHours(3),
            'end_time' => now(),
            'start_cash' => $startCash,
            'expected_cash' => $startCash,
            'end_cash' => $endCash,
            'difference' => $difference,
            'total_sales' => 0,
        ]);
    }
}
