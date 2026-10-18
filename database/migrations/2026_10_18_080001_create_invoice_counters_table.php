<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Layer 4 Data Guard — nomor invoice bebas race.
     *
     * Generate invoice sebelumnya membaca MAX(invoice_no) tanpa kunci → dua kasir
     * bertransaksi bersamaan bisa mendapat nomor sama (lalu gagal di UNIQUE index,
     * atau nomor loncat). Counter per hari yang dikunci `lockForUpdate` menjamin
     * nomor berurutan tanpa duplikat.
     */
    public function up(): void
    {
        Schema::create('invoice_counters', function (Blueprint $table): void {
            $table->string('day', 8)->primary(); // YYYYMMDD
            $table->unsignedInteger('sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_counters');
    }
};
