<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    private User $otherCashier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->cashier = User::factory()->create(['role' => 'kasir']);
        $this->cashier->assignRole('kasir');

        $this->otherCashier = User::factory()->create(['role' => 'kasir']);
        $this->otherCashier->assignRole('kasir');

        $product = Product::factory()->create(['stock_alert' => 5]);
        $this->variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 10000,
            'cost' => 5000,
            'stock' => 100,
        ]);
    }

    public function test_sales_report_without_filters_lists_all_transactions(): void
    {
        $sale = $this->makeSale($this->cashier);
        $voided = $this->makeSale($this->cashier);
        app(TransactionService::class)->voidTransaction($voided, $this->admin);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.sales'))
            ->assertOk();

        $transactions = $response->viewData('transactions');
        $this->assertSame(2, $transactions->total());
        $this->assertEqualsCanonicalizing(
            [$sale->id, $voided->id],
            $transactions->getCollection()->pluck('id')->all(),
        );
    }

    public function test_sales_report_filters_by_date_cashier_and_payment_method(): void
    {
        $today = now()->startOfDay();
        $matching = $this->makeSale($this->cashier, 'qris', $today->copy()->addHour());
        $this->makeSale($this->cashier, 'cash', $today->copy()->addHours(2));
        $this->makeSale($this->otherCashier, 'qris', $today->copy()->addHours(3));
        $this->makeSale($this->cashier, 'qris', $today->copy()->subDay());

        $response = $this->actingAs($this->admin)->get(route('reports.sales', [
            'date_from' => $today->toDateString(),
            'date_to' => $today->toDateString(),
            'user_id' => $this->cashier->id,
            'method' => 'qris',
        ]))->assertOk();

        $transactions = $response->viewData('transactions');
        $this->assertSame(1, $transactions->total());
        $this->assertSame($matching->id, $transactions->first()->id);
    }

    public function test_sales_summary_covers_all_filtered_pages_and_excludes_voided_sales(): void
    {
        $transactions = [];
        for ($i = 0; $i < 21; $i++) {
            $transactions[] = $this->makeSale($this->cashier);
        }

        app(TransactionService::class)->voidTransaction($transactions[0], $this->admin);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.sales'))
            ->assertOk();

        $this->assertSame(20, $response->viewData('transactions')->count());
        $this->assertSame(21, $response->viewData('transactions')->total());

        $summary = $response->viewData('summary');
        $this->assertSame(20, $summary['count']);
        $this->assertSame(20 * 11100, $summary['omzet']);
        $this->assertSame(20 * 1100, $summary['tax']);
    }

    public function test_stock_report_only_lists_variants_at_or_below_minimum(): void
    {
        $lowStock = ProductVariant::factory()->create([
            'product_id' => $this->variant->product_id,
            'stock' => 5,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $this->variant->product_id,
            'stock' => 6,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.stock'))
            ->assertOk();

        $variants = $response->viewData('variants');
        $this->assertCount(1, $variants);
        $this->assertSame($lowStock->id, $variants->first()->id);
    }

    public function test_csv_export_applies_all_report_filters_and_excludes_voided_transactions(): void
    {
        $today = now()->startOfDay();
        $matching = $this->makeSale($this->cashier, 'qris', $today->copy()->addHour());
        $voided = $this->makeSale($this->cashier, 'qris', $today->copy()->addHours(2));
        app(TransactionService::class)->voidTransaction($voided, $this->admin);
        $wrongMethod = $this->makeSale($this->cashier, 'cash', $today->copy()->addHours(3));
        $wrongCashier = $this->makeSale($this->otherCashier, 'qris', $today->copy()->addHours(4));
        $wrongDate = $this->makeSale($this->cashier, 'qris', $today->copy()->subDay());

        $response = $this->actingAs($this->admin)
            ->get(route('reports.export', [
                'date_from' => $today->toDateString(),
                'date_to' => $today->toDateString(),
                'user_id' => $this->cashier->id,
                'method' => 'qris',
            ]))
            ->assertDownload();

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($matching->invoice_no, $csv);
        $this->assertStringNotContainsString($voided->invoice_no, $csv);
        $this->assertStringNotContainsString($wrongMethod->invoice_no, $csv);
        $this->assertStringNotContainsString($wrongCashier->invoice_no, $csv);
        $this->assertStringNotContainsString($wrongDate->invoice_no, $csv);
    }

    public function test_transaction_history_filters_by_date_range(): void
    {
        $today = now()->startOfDay();
        $matching = $this->makeSale($this->cashier, 'cash', $today->copy()->addHour());
        $this->makeSale($this->cashier, 'cash', $today->copy()->subDay());

        $transactions = $this->actingAs($this->admin)
            ->get(route('transactions.index', [
                'date_from' => $today->toDateString(),
                'date_to' => $today->toDateString(),
            ]))
            ->assertOk()
            ->viewData('transactions');

        $this->assertSame(1, $transactions->total());
        $this->assertSame($matching->id, $transactions->first()->id);
    }

    private function makeSale(User $user, string $method = 'cash', mixed $createdAt = null): Transaction
    {
        $transaction = app(TransactionService::class)->processTransaction(
            user: $user,
            cart: [[
                'id' => $this->variant->id,
                'name' => $this->variant->display_name,
                'price' => 1,
                'qty' => 1,
            ]],
            customerId: null,
            paymentMethod: $method,
            amountTendered: 100000,
        );

        if ($createdAt !== null) {
            Transaction::query()->whereKey($transaction->id)->update(['created_at' => $createdAt]);
        }

        return $transaction->fresh();
    }
}
