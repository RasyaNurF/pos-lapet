<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\SettingService;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_load_a_receipt_with_item_and_payment_details(): void
    {
        $this->seed(PermissionSeeder::class);
        $cashier = User::factory()->create(['role' => 'kasir']);
        $cashier->assignRole('kasir');
        $variant = ProductVariant::factory()->create(['price' => 10000, 'stock' => 5]);
        $transaction = app(TransactionService::class)->processTransaction(
            $cashier, [['id' => $variant->id, 'qty' => 2]], null, 'cash', 50000,
        );

        $this->actingAs($cashier)
            ->get(route('transactions.receipt', $transaction))
            ->assertOk()
            ->assertSee($transaction->invoice_no)
            ->assertSee($variant->display_name)
            ->assertSee('Rp 22.200')
            ->assertSee('CASH')
            ->assertSee('Tunai Diterima')->assertSee('Rp 50.000')
            ->assertSee('Kembalian')->assertSee('Rp 27.800');

        $this->assertSame(50000, $transaction->fresh()->cash_tendered);
        $this->assertSame(27800, $transaction->fresh()->change_amount);
        $this->assertSame(22200, $transaction->payments->sum('amount'));
    }

    public function test_receipt_printing_still_requires_print_permission(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $variant = ProductVariant::factory()->create(['price' => 10000, 'stock' => 5]);
        $transaction = app(TransactionService::class)->processTransaction(
            $user, [['id' => $variant->id, 'qty' => 1]], null, 'cash', 50000,
        );

        $this->actingAs($user)
            ->get(route('transactions.receipt', $transaction))
            ->assertForbidden();
    }

    public function test_receipt_displays_uploaded_logo_and_configured_store_identity(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('store-logo.png')->store('settings', 'public');
        app(SettingService::class)->set([
            'company_logo' => $path,
            'company_name' => 'Toko Hewan Baru',
            'company_address' => "Jalan Mawar 10\nJakarta",
            'company_phone' => '08123456789',
            'company_email' => 'halo@tokobaru.test',
            'company_website' => 'https://tokobaru.test',
        ]);
        $this->seed(PermissionSeeder::class);
        $cashier = User::factory()->create();
        $cashier->assignRole('kasir');
        $variant = ProductVariant::factory()->create(['price' => 10000, 'stock' => 5]);
        $transaction = app(TransactionService::class)->processTransaction(
            $cashier, [['id' => $variant->id, 'qty' => 1]], null, 'cash', 50000,
        );
        config(['filesystems.disks.public.url' => 'http://wrong-host.test/storage']);
        $this->actingAs($cashier)->get('http://127.0.0.1:8000/transactions/'.$transaction->id.'/receipt')
            ->assertOk()
            ->assertSee('src="http://127.0.0.1:8000/storage/'.$path.'"', false)
            ->assertSee('Toko Hewan Baru')->assertSee('Jalan Mawar 10')->assertSee('Jakarta')
            ->assertSee('08123456789')->assertSee('halo@tokobaru.test')->assertSee('https://tokobaru.test')
            ->assertDontSee('LA PET GALLERY')->assertDontSee('wrong-host.test');
    }

    public function test_exact_cash_and_noncash_payments_do_not_create_false_change(): void
    {
        $user = User::factory()->create();
        $variant = ProductVariant::factory()->create(['price' => 10000, 'stock' => 5]);
        $service = app(TransactionService::class);
        $cash = $service->processTransaction($user, [['id' => $variant->id, 'qty' => 1]], null, 'cash', 11100);
        $this->assertSame(11100, $cash->cash_tendered);
        $this->assertSame(0, $cash->change_amount);
        $qris = $service->processTransaction($user, [['id' => $variant->id, 'qty' => 1]], null, 'qris', 50000);
        $this->assertNull($qris->cash_tendered);
        $this->assertNull($qris->change_amount);
    }

    public function test_split_payment_only_records_the_cash_portion_as_cash_received(): void
    {
        $user = User::factory()->create();
        $variant = ProductVariant::factory()->create(['price' => 10000, 'stock' => 5]);
        foreach ([['cash', 'bank', 4000], ['bank', 'cash', 7100]] as [$first, $second, $cashAmount]) {
            $sale = app(TransactionService::class)->processTransaction(
                $user, [['id' => $variant->id, 'qty' => 1]], null, $first, 4000,
                splitPrimaryAmount: 4000, secondPaymentMethod: $second,
            );
            $this->assertSame($cashAmount, $sale->cash_tendered);
            $this->assertSame(0, $sale->change_amount);
            $this->assertSame(11100, $sale->payments->sum('amount'));
        }
    }

    public function test_duplicate_checkout_preserves_original_cash_and_change(): void
    {
        $user = User::factory()->create();
        $variant = ProductVariant::factory()->create(['price' => 10000, 'stock' => 5]);
        $service = app(TransactionService::class);
        $sale = $service->processTransaction($user, [['id' => $variant->id, 'qty' => 1]], null, 'cash', 50000, clientToken: 'same-sale');
        $retry = $service->processTransaction($user, [['id' => $variant->id, 'qty' => 1]], null, 'cash', 20000, clientToken: 'same-sale');
        $this->assertSame($sale->id, $retry->id);
        $this->assertSame(50000, $retry->cash_tendered);
        $this->assertSame(38900, $retry->change_amount);
        $this->assertSame(4, $variant->fresh()->stock);
    }

    public function test_old_receipt_does_not_invent_cash_received_or_change(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('kasir');
        $variant = ProductVariant::factory()->create(['price' => 10000, 'stock' => 5]);
        $sale = app(TransactionService::class)->processTransaction($user, [['id' => $variant->id, 'qty' => 1]], null, 'cash', 50000);
        $sale->update(['cash_tendered' => null, 'change_amount' => null]);
        $this->actingAs($user)->get(route('transactions.receipt', $sale))->assertOk()
            ->assertSee($sale->invoice_no)->assertDontSee('Tunai Diterima')->assertDontSee('Kembalian');
    }
}
