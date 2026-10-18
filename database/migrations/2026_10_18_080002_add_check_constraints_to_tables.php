<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Layer 4 Data Guard — jaring pengaman terakhir di level database.
     *
     * Guard aplikasi (FormRequest + Service) bisa dilewati bila ada bug atau
     * query mentah. CHECK constraint memaksa angka negatif tetap ditolak DB.
     *
     * Catatan: kolom uang sudah `unsigned` (MySQL tolak negatif). Gap nyata ada di
     * `product_variants.stock` yang memakai `integer` signed — itu yang paling
     * kritis karena stok negatif membuat laporan & stock opname tidak valid.
     *
     * Driver-aware: SQLite tidak mendukung `ALTER TABLE ... ADD CONSTRAINT`, jadi
     * pada driver itu guard ini dilewati (suite test memakai SQLite :memory:).
     * Guard aktif penuh di MySQL — driver produksi.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->constraints() as $table => $list) {
            foreach ($list as [$name, $expression]) {
                if ($this->constraintExists($table, $name)) {
                    continue;
                }

                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->constraints() as $table => $list) {
            foreach ($list as [$name]) {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            }
        }
    }

    /**
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private function constraints(): array
    {
        return [
            'product_variants' => [
                ['chk_variants_stock_nonneg', 'stock >= 0'],
                ['chk_variants_price_nonneg', 'price >= 0'],
            ],
            'transaction_items' => [
                ['chk_items_qty_positive', 'qty > 0'],
            ],
            'transactions' => [
                ['chk_tx_subtotal_nonneg', 'subtotal >= 0'],
                ['chk_tx_discount_nonneg', 'discount >= 0'],
                ['chk_tx_tax_nonneg', 'tax >= 0'],
                ['chk_tx_total_nonneg', 'total >= 0'],
            ],
            'transaction_payments' => [
                ['chk_payments_amount_nonneg', 'amount >= 0'],
            ],
            'shifts' => [
                ['chk_shifts_start_cash_nonneg', 'start_cash >= 0'],
            ],
        ];
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?',
            [$table, $name],
        )->c > 0;
    }
};
