<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot tarif PPN per transaksi.
     *
     * Sebelumnya tarif di-hardcode 0.11 di TransactionService dan PosTerminal,
     * sehingga struk lama tidak pernah bisa tampil benar setelah tarif berubah.
     * Dengan kolom ini, mengubah `tax_rate` di pengaturan TIDAK mengubah
     * riwayat — struk lama tetap menampilkan tarif yang benar-benar dipakai.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->decimal('tax_rate', 5, 2)->default(11)->after('tax');
        });

        // Backfill: seluruh transaksi yang sudah ada memang dihitung PPN 11%.
        DB::table('transactions')->update(['tax_rate' => 11]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('tax_rate');
        });
    }
};
