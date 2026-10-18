<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Layer 4 Data Guard — CHECK constraint di level database.
 *
 * Suite test memakai SQLite yang tidak mendukung ALTER ADD CONSTRAINT, jadi
 * migrasi memang dirancang no-op di sini (guard aktif di MySQL produksi).
 * Test ini memastikan: (1) migrasi tetap aman dijalankan di SQLite, dan
 * (2) bila berjalan di MySQL, semua constraint benar-benar terdaftar.
 */
class GuardDatabaseConstraintTest extends TestCase
{
    use RefreshDatabase;

    private const array EXPECTED_CONSTRAINTS = [
        'product_variants' => ['chk_variants_stock_nonneg', 'chk_variants_price_nonneg'],
        'transaction_items' => ['chk_items_qty_positive'],
        'transactions' => ['chk_tx_subtotal_nonneg', 'chk_tx_discount_nonneg', 'chk_tx_tax_nonneg', 'chk_tx_total_nonneg'],
        'transaction_payments' => ['chk_payments_amount_nonneg'],
        'shifts' => ['chk_shifts_start_cash_nonneg'],
    ];

    public function test_migration_does_not_break_schema_on_sqlite(): void
    {
        // RefreshDatabase sudah menjalankan seluruh migrasi termasuk guard ini.
        // Ini membuktikan cabang driver-aware tidak melempar error di SQLite.
        foreach (array_keys(self::EXPECTED_CONSTRAINTS) as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} harus tetap ada.");
        }

        // Insert valid tetap diterima (guard tidak menghalangi data sah).
        DB::table('product_variants')->insert([
            'product_id' => DB::table('products')->insertGetId([
                'sku' => 'CHK-OK',
                'name' => 'Produk Cek',
                'base_price' => 1000,
                'base_cost' => 500,
                'stock_alert' => 2,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Porsi',
            'sku' => 'CHK-OK-V1',
            'price' => 1000,
            'cost' => 500,
            'stock' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('product_variants', ['sku' => 'CHK-OK-V1', 'stock' => 5]);
    }

    public function test_check_constraints_are_registered_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraint hanya didukung MySQL (SQLite tanpa ALTER ADD CONSTRAINT).');
        }

        $registered = [];
        foreach (DB::select(
            "SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'"
        ) as $row) {
            $registered[$row->TABLE_NAME][] = $row->CONSTRAINT_NAME;
        }

        foreach (self::EXPECTED_CONSTRAINTS as $table => $names) {
            foreach ($names as $name) {
                $this->assertContains(
                    $name,
                    $registered[$table] ?? [],
                    "CHECK constraint {$table}.{$name} belum terdaftar.",
                );
            }
        }
    }

    public function test_database_rejects_negative_stock_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraint hanya didukung MySQL.');
        }

        $productId = DB::table('products')->insertGetId([
            'sku' => 'CHK-NEG',
            'name' => 'Produk Negatif',
            'base_price' => 1000,
            'base_cost' => 500,
            'stock_alert' => 2,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('product_variants')->insert([
            'product_id' => $productId,
            'name' => 'Porsi',
            'sku' => 'CHK-NEG-V1',
            'price' => 1000,
            'cost' => 500,
            'stock' => -5, // ditolak chk_variants_stock_nonneg
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
