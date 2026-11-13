<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount_tendered')->nullable()->after('amount');
            $table->unsignedBigInteger('change_amount')->nullable()->after('amount_tendered');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_payments', function (Blueprint $table): void {
            $table->dropColumn(['amount_tendered', 'change_amount']);
        });
    }
};
