<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Layer 4 Data Guard — idempotency checkout.
     *
     * Token unik per aksi "Bayar". Klik bayar ganda (double-tap / retry jaringan)
     * mengirim token yang sama sehingga transaksi kedua **tidak** diproses ulang
     * (stok tidak terpotong dua kali, invoice tidak dobel).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('client_token', 64)->nullable()->unique()->after('invoice_no');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['client_token']);
            $table->dropColumn('client_token');
        });
    }
};
