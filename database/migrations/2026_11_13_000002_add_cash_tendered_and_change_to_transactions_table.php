<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // NULL berarti transaksi lama belum mencatat uang diterima.
            $table->unsignedBigInteger('cash_tendered')->nullable()->after('total');
            $table->unsignedBigInteger('change_amount')->nullable()->after('cash_tendered');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['cash_tendered', 'change_amount']);
        });
    }
};
