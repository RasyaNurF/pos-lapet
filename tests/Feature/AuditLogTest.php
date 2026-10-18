<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\PosTerminal;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Layer 5 Operational Guard — jejak audit cakupan penuh.
 *
 * Setiap aksi sensitif wajib meninggalkan baris audit: siapa, apa, old → new.
 */
class AuditLogTest extends TestCase
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
    // CRUD produk
    // ------------------------------------------------------------------

    public function test_product_create_is_audited(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), [
            'sku' => 'AUD-001',
            'name' => 'Produk Audit',
            'category_id' => null,
            'base_price' => 15000,
            'base_cost' => 8000,
            'stock_alert' => 2,
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product.create',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_product_update_records_old_and_new_values(): void
    {
        $product = Product::factory()->create(['name' => 'Nama Lama', 'base_price' => 10000]);

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'sku' => $product->sku,
            'name' => 'Nama Baru',
            'category_id' => null,
            'base_price' => 20000,
            'base_cost' => 5000,
            'stock_alert' => 2,
            'is_active' => 1,
        ])->assertRedirect();

        $log = AuditLog::query()->where('action', 'product.update')->first();

        $this->assertNotNull($log, 'product.update harus tercatat.');
        $this->assertSame('Nama Lama', $log->old_values['name'] ?? null);
        $this->assertSame('Nama Baru', $log->new_values['name'] ?? null);
        $this->assertSame(10000, $log->old_values['base_price'] ?? null);
        $this->assertSame(20000, $log->new_values['base_price'] ?? null);
    }

    public function test_product_delete_is_audited_with_snapshot(): void
    {
        $product = Product::factory()->create(['sku' => 'DEL-001', 'name' => 'Akan Dihapus']);

        $this->actingAs($this->admin)->delete(route('products.destroy', $product))
            ->assertRedirect();

        $log = AuditLog::query()->where('action', 'product.delete')->first();

        $this->assertNotNull($log);
        $this->assertSame('DEL-001', $log->old_values['sku'] ?? null);
        // Soft delete (ADR-0026): data bisnis tetap ada.
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    // ------------------------------------------------------------------
    // Penyesuaian stok (celah fraud internal)
    // ------------------------------------------------------------------

    public function test_stock_adjustment_is_audited_with_old_and_new(): void
    {
        $variant = $this->makeVariant(stock: 5);

        $this->actingAs($this->admin)
            ->put(route('products.variant.stock', $variant), ['stock' => 12])
            ->assertRedirect();

        $log = AuditLog::query()->where('action', 'stock.adjust')->first();

        $this->assertNotNull($log, 'stock.adjust wajib tercatat.');
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(5, $log->old_values['stock'] ?? null);
        $this->assertSame(12, $log->new_values['stock'] ?? null);
    }

    // ------------------------------------------------------------------
    // Kategori & pelanggan
    // ------------------------------------------------------------------

    public function test_category_crud_is_audited(): void
    {
        $this->actingAs($this->admin)->post(route('categories.store'), ['name' => 'Kategori Audit'])
            ->assertRedirect();

        $category = Category::query()->where('name', 'Kategori Audit')->firstOrFail();

        $this->actingAs($this->admin)->put(route('categories.update', $category), ['name' => 'Kategori Baru'])
            ->assertRedirect();

        $this->actingAs($this->admin)->delete(route('categories.destroy', $category))
            ->assertRedirect();

        foreach (['category.create', 'category.update', 'category.delete'] as $action) {
            $logged = AuditLog::query()
                ->where('action', $action)
                ->where('user_id', $this->admin->id)
                ->where('auditable_id', $category->id)
                ->exists();

            $this->assertTrue($logged, "Audit {$action} tidak tercatat.");
        }
    }

    public function test_customer_crud_is_audited(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), [
            'name' => 'Pelanggan Audit',
            'phone' => '0812000000',
            'email' => 'audit@mail.test',
        ])->assertRedirect();

        $customer = Customer::query()->where('name', 'Pelanggan Audit')->firstOrFail();

        $this->actingAs($this->admin)->put(route('customers.update', $customer), [
            'name' => 'Pelanggan Diubah',
            'phone' => '0813000000',
            'email' => 'audit@mail.test',
        ])->assertRedirect();

        foreach (['customer.create', 'customer.update'] as $action) {
            $logged = AuditLog::query()
                ->where('action', $action)
                ->where('user_id', $this->admin->id)
                ->where('auditable_id', $customer->id)
                ->exists();

            $this->assertTrue($logged, "Audit {$action} tidak tercatat.");
        }
    }

    // ------------------------------------------------------------------
    // Void transaksi
    // ------------------------------------------------------------------

    public function test_void_transaction_is_audited(): void
    {
        $variant = $this->makeVariant(stock: 10);

        Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->set('amount_tendered', 100000)
            ->call('openPaymentPanel')
            ->call('checkout');

        $transaction = Transaction::query()->latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post(route('transactions.void', $transaction), [
            'reason' => 'Salah input',
        ])->assertRedirect();

        $log = AuditLog::query()->where('action', 'transaction.void')->first();

        $this->assertNotNull($log, 'transaction.void wajib tercatat.');
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('completed', $log->old_values['status'] ?? null);
        $this->assertSame('voided', $log->new_values['status'] ?? null);
    }

    // ------------------------------------------------------------------
    // Shift
    // ------------------------------------------------------------------

    public function test_shift_open_and_close_are_audited(): void
    {
        $user = User::factory()->create(['role' => 'kasir', 'shift_status' => 'closed']);
        $user->assignRole('kasir');

        $service = app(ShiftService::class);
        $service->openShift($user->fresh(), 100000);
        $service->closeShift($user->fresh(), 120000);

        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.open']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.close']);
    }

    // ------------------------------------------------------------------
    // Halaman & permission
    // ------------------------------------------------------------------

    public function test_admin_can_view_audit_logs_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('Audit Log');
    }

    public function test_kasir_cannot_view_audit_logs(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('audit-logs.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('audit-logs.index'))->assertRedirect(route('login'));
    }

    public function test_kasir_permissions_do_not_include_audit_view(): void
    {
        $this->assertFalse($this->kasir->can('audit.view'));
        $this->assertTrue($this->admin->can('audit.view'));
    }

    public function test_audit_log_has_no_update_or_delete_endpoints(): void
    {
        // Append-only: tidak ada route maupun permission untuk mengubah/menghapus log.
        $this->assertFalse(route('audit-logs.index') === '');
        $this->assertFalse($this->admin->can('audit.delete'));
        $this->assertFalse($this->admin->can('audit.update'));
    }
}
