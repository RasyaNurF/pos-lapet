<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Layer 4 Data Guard — riwayat penjualan tidak boleh ikut terhapus.
     *
     * `shifts`, `transactions`, dan `suspended_transactions` semuanya dibuat
     * dengan `ON DELETE CASCADE` ke `users`. Saat itu users belum punya soft
     * delete, jadi cascade memang tidak pernah tersentuh.
     *
     * Sekarang users sudah soft delete, cascade itu berubah jadi ranjau:
     * satu pemanggilan `forceDelete()` pada kasir yang keluar akan menghapus
     * seluruh shift, transaksi, dan kas tunda miliknya — riwayat keuangan
     * toko hilang permanen tanpa peringatan apa pun.
     *
     * `audit_logs.user_id` sengaja TIDAK disentuh: sudah `NULL ON DELETE`,
     * dan justru harus bertahan sebagai jejak.
     *
     * Driver-aware mengikuti pola `add_check_constraints_to_tables`: SQLite
     * tidak mendukung mengubah FK yang sudah ada, jadi pada driver itu guard
     * dilewati (suite test memakai SQLite :memory:). Guard aktif penuh di
     * MySQL — driver produksi.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables() as $table) {
            $name = "{$table}_user_id_foreign";

            if (! $this->foreignKeyExists($table, 'user_id')) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$name}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$name}
                 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables() as $table) {
            $name = "{$table}_user_id_foreign";

            if (! $this->foreignKeyExists($table, 'user_id')) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$name}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$name}
                 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE",
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        return ['shifts', 'transactions', 'suspended_transactions'];
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME = ?',
            [$table, $column, 'users'],
        )->c > 0;
    }
};
