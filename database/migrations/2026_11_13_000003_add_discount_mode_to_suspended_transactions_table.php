<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suspended_transactions', function (Blueprint $table): void {
            // Hold lama mempertahankan nominal diskon yang sudah tersimpan.
            $table->boolean('discount_is_manual')->default(true)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('suspended_transactions', function (Blueprint $table): void {
            $table->dropColumn('discount_is_manual');
        });
    }
};
