<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\PosTerminal;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SuspendedTransaction;
use App\Models\User;
use App\Services\SettingService;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConfigurableTaxTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create(['role' => 'kasir', 'shift_status' => 'open']);
        $this->kasir->assignRole('kasir');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');
    }

    private function makeVariant(int $price = 10000, int $stock = 100): ProductVariant
    {
        $product = Product::factory()->create(['base_price' => $price, 'base_cost' => 5000]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => $price,
            'cost' => 5000,
            'stock' => $stock,
        ]);
    }

    private function checkout(ProductVariant $variant, int $qty = 1, int $discount = 0): object
    {
        return app(TransactionService::class)->processTransaction(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => $variant->display_name, 'price' => $variant->price, 'qty' => $qty]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: PHP_INT_MAX,
            discount: $discount,
        );
    }

    // ------------------------------------------------------------------
    // Regresi: default harus identik dengan perilaku lama
    // ------------------------------------------------------------------

    /**
     * Sebelum kolom tax_rate ada, tarif di-hardcode 0.11. Mengubahnya jadi
     * configurable justru tidak boleh mengubah satu pun angka pun.
     */
    public function test_default_settings_keep_legacy_totals_untouched(): void
    {
        $transaction = $this->checkout($this->makeVariant(price: 100000), qty: 3, discount: 10000);

        // subtotal 300000, pajak 11% = 33000, total 300000 + 33000 - 10000
        $this->assertEquals(300000, $transaction->subtotal);
        $this->assertEquals(33000, $transaction->tax);
        $this->assertEquals(10000, $transaction->discount);
        $this->assertEquals(323000, $transaction->total);
    }

    public function test_receipt_label_defaults_to_11_percent(): void
    {
        $transaction = $this->checkout($this->makeVariant());

        $this->assertEquals(11.0, (float) $transaction->tax_rate);
        $this->assertSame('11', $transaction->tax_rate_label);
    }

    // ------------------------------------------------------------------
    // Tarif mengikuti pengaturan & snapshot
    // ------------------------------------------------------------------

    public function test_tax_follows_store_setting(): void
    {
        app(SettingService::class)->set(['tax_rate' => 20]);

        $transaction = $this->checkout($this->makeVariant(price: 100000));

        $this->assertEquals(20000, $transaction->tax);
        $this->assertEquals(120000, $transaction->total);
    }

    /**
     * Tarif disimpan per transaksi, jadi riwayat tidak ikut berubah ketika
     * admin mengubah pengaturan PPN nanti.
     */
    public function test_tax_rate_is_snapshotted_per_transaction(): void
    {
        $before = $this->checkout($this->makeVariant(price: 100000));

        app(SettingService::class)->set(['tax_rate' => 25]);

        $after = $this->checkout($this->makeVariant(price: 100000));

        $this->assertEquals(11000, $before->fresh()->tax);
        $this->assertEquals(11.0, (float) $before->fresh()->tax_rate);
        $this->assertSame('11', $before->fresh()->tax_rate_label);

        $this->assertEquals(25000, $after->fresh()->tax);
        $this->assertEquals(25.0, (float) $after->fresh()->tax_rate);
    }

    /**
     * Struk lama harus menampilkan tarif lamanya, bukan tarif yang baru saja
     * diubah admin — inilah gunanya snapshot.
     */
    public function test_receipt_shows_historical_rate_not_current_setting(): void
    {
        $transaction = $this->checkout($this->makeVariant());

        app(SettingService::class)->set(['tax_rate' => 25]);

        $this->actingAs($this->admin)
            ->get(route('transactions.receipt', $transaction))
            ->assertOk()
            ->assertSee('Pajak (11%)')
            ->assertDontSee('Pajak (25%)');
    }

    public function test_fractional_rate_is_rendered_without_trailing_zero(): void
    {
        app(SettingService::class)->set(['tax_rate' => '11.5']);

        $transaction = $this->checkout($this->makeVariant(price: 200000));

        $this->assertSame('11.5', $transaction->tax_rate_label);
        $this->assertEquals(23000, $transaction->tax); // round(200000 * 11.5 / 100)
    }

    // ------------------------------------------------------------------
    // PPN termasuk harga
    // ------------------------------------------------------------------

    /**
     * Pemilihan subtotal di tes ini bukan sembarang angka:
     * 1.110.000 = 1.000.000 bersih yang sudah mengandung PPN 11%, sehingga
     * PPN yang dikeluarkan tepat 110.000 tanpa pembulatan.
     */
    public function test_tax_included_is_extracted_from_subtotal(): void
    {
        app(SettingService::class)->set(['tax_included' => true]);

        $transaction = $this->checkout($this->makeVariant(price: 1110000));

        // 1110000 * 11 / 111 = 110000, dan PPN ini TIDAK ditambahkan lagi.
        $this->assertEquals(110000, $transaction->tax);
        $this->assertEquals(1110000, $transaction->total);
    }

    public function test_tax_included_still_deducts_discount(): void
    {
        app(SettingService::class)->set(['tax_included' => true]);

        $transaction = $this->checkout($this->makeVariant(price: 1110000), discount: 5000);

        $this->assertEquals(110000, $transaction->tax);
        $this->assertEquals(1105000, $transaction->total); // 1110000 - 5000
    }

    // ------------------------------------------------------------------
    // Preview POS = tagihan backend (ini yang paling mahal kalau salah)
    // ------------------------------------------------------------------

    /**
     * Preview di layar kasir harus SELALU sama dengan yang dibebankan.
     *
     * Dibuktikan dengan menyatakan angka yang sama pada kedua sisi — preview
     * lewat assertSet, tagihan lewat nilai tersimpan — sehingga tidak ada satu
     * pun rumus yang bisa diam-diam menyimpang.
     */
    public function test_pos_preview_matches_backend_charge(): void
    {
        app(SettingService::class)->set(['tax_rate' => 20]);

        $variant = $this->makeVariant(price: 25000);

        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->call('addToCart', $variant->id)
            ->call('addToCart', $variant->id)
            ->assertSet('subtotal', 75000)
            ->assertSet('tax', 15000)
            ->assertSet('total', 90000);

        $transaction = $this->checkout($variant, qty: 3);

        $this->assertEquals(75000, $transaction->subtotal);
        $this->assertEquals(15000, $transaction->tax);
        $this->assertEquals(90000, $transaction->total);
    }

    public function test_pos_preview_matches_backend_when_tax_included(): void
    {
        app(SettingService::class)->set(['tax_included' => true]);

        $variant = $this->makeVariant(price: 1110000);

        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->assertSet('subtotal', 1110000)
            ->assertSet('tax', 110000)
            ->assertSet('total', 1110000);

        $transaction = $this->checkout($variant, qty: 1);

        $this->assertEquals(110000, $transaction->tax);
        $this->assertEquals(1110000, $transaction->total);
    }

    // ------------------------------------------------------------------
    // Diskon default toko
    // ------------------------------------------------------------------

    public function test_default_discount_is_prefilled_when_first_item_added(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);

        $variant = $this->makeVariant(price: 100000);

        // 10% dari 100000
        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->assertSet('discount', 10000)
            ->assertSet('total', 101000); // 100000 + 11000 - 10000
    }

    /**
     * Kasir bebas mengubah diskon; penambahan item kedua tidak boleh
     * menimpa pilihan kasir.
     */
    public function test_cashier_override_is_not_clobbered_by_later_items(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);

        $variant = $this->makeVariant(price: 100000);

        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->set('discount', 15000)
            ->call('addToCart', $variant->id)
            ->assertSet('discount', 15000);
    }

    /**
     * Diskon default 0% = tidak ada yang berubah sama sekali terhadap perilaku
     * lama. Inilah alasan fitur ini aman untuk toko yang tidak memakainya.
     */
    public function test_zero_default_discount_leaves_behaviour_unchanged(): void
    {
        $variant = $this->makeVariant(price: 10000);

        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->assertSet('discount', 0)
            ->assertSet('subtotal', 10000)
            ->assertSet('tax', 1100)
            ->assertSet('total', 11100);
    }

    /**
     * Diskon default tidak boleh melebihi subtotal.
     */
    public function test_default_discount_is_capped_at_subtotal(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 100]);

        $variant = $this->makeVariant(price: 50000);

        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('addToCart', $variant->id)
            ->assertSet('discount', 50000);
    }

    /**
     * Resume daftar tunda membawa diskon tersimpan — jangan ditimpa default.
     */
    public function test_resume_does_not_reapply_default_discount(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);

        $variant = $this->makeVariant(price: 100000);

        $suspended = app(TransactionService::class)->suspend(
            user: $this->kasir,
            cart: [['id' => $variant->id, 'name' => 'X', 'price' => 100000, 'qty' => 1]],
            customerId: null,
            discount: 7000,
            label: 'Tunda',
        );

        Livewire::actingAs($this->kasir)
            ->test(PosTerminal::class)
            ->call('resumeTransaction', $suspended->id)
            ->assertSet('discount', 7000);
    }

    public function test_default_percentage_follows_added_removed_and_changed_quantities(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);
        $first = $this->makeVariant(price: 100000);
        $second = $this->makeVariant(price: 50000);
        Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $first->id)->assertSet('discount', 10000)
            ->call('increaseQty', $first->id)->assertSet('discount', 20000)->assertSet('total', 202000)
            ->call('addToCart', $second->id)->assertSet('discount', 25000)->assertSet('total', 252500)
            ->call('decreaseQty', $first->id)->assertSet('discount', 15000)
            ->call('removeFromCart', $second->id)->assertSet('discount', 10000)
            ->call('decreaseQty', $first->id)->assertSet('discount', 0)->assertSet('total', 0)
            ->call('addByBarcode', $second->sku)->assertSet('discount', 5000);
    }

    public function test_fractional_percentage_rounds_against_current_subtotal(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 12.5]);
        $variant = $this->makeVariant(price: 999);
        Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $variant->id)->assertSet('discount', 125)
            ->call('increaseQty', $variant->id)->assertSet('discount', 250)
            ->call('decreaseQty', $variant->id)->assertSet('discount', 125);
    }

    public function test_manual_zero_discount_is_preserved_until_the_cart_is_emptied(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);
        $variant = $this->makeVariant(price: 100000);
        Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $variant->id)->set('discount', 0)
            ->call('increaseQty', $variant->id)->assertSet('discount', 0)
            ->call('decreaseQty', $variant->id)->assertSet('discount', 0)
            ->call('removeFromCart', $variant->id)->assertSet('discount', 0)
            ->call('addToCart', $variant->id)->assertSet('discount', 10000);
    }

    public function test_automatic_discount_remains_a_percentage_after_suspend_and_resume(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);
        $variant = $this->makeVariant(price: 100000);
        $component = Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $variant->id)->call('suspendTransaction');
        $hold = SuspendedTransaction::firstOrFail();
        $this->assertFalse($hold->discount_is_manual);
        $component->call('resumeTransaction', $hold->id)
            ->assertSet('discount', 10000)
            ->call('increaseQty', $variant->id)->assertSet('discount', 20000)
            ->call('quickTender', 250000)->call('checkout')->assertDispatched('swal:success');
        $this->assertDatabaseHas('transactions', ['subtotal' => 200000, 'discount' => 20000, 'total' => 202000]);
    }

    public function test_manual_discount_remains_fixed_after_suspend_and_resume(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);
        $variant = $this->makeVariant(price: 100000);
        $component = Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $variant->id)->set('discount', 7000)->call('suspendTransaction');
        $hold = SuspendedTransaction::firstOrFail();
        $this->assertTrue($hold->discount_is_manual);
        $component->call('resumeTransaction', $hold->id)
            ->call('increaseQty', $variant->id)->assertSet('discount', 7000);
    }

    public function test_checkout_recalculates_automatic_discount_using_current_database_prices(): void
    {
        app(SettingService::class)->set(['sales_default_discount' => 10]);
        $variant = $this->makeVariant(price: 100000);
        $component = Livewire::actingAs($this->kasir)->test(PosTerminal::class)
            ->call('addToCart', $variant->id)->assertSet('discount', 10000);
        $variant->update(['price' => 200000]);
        $component->call('quickTender', 250000)->call('checkout')->assertDispatched('swal:success');
        $this->assertDatabaseHas('transactions', ['subtotal' => 200000, 'discount' => 20000, 'total' => 202000]);
    }
}
