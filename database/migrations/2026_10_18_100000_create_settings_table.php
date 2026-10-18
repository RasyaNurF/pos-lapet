<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel pengaturan toko (Store Config).
     *
     * Berbentuk key-value karena store config cenderung bertambah — menambah
     * pengaturan baru cukup lewat `config/settings.php`, tanpa migrasi.
     *
     * Validasi tipe TIDAK diserahkan ke database, melainkan ke registry di
     * `config/settings.php`, supaya `tax_rate` tidak bisa menerima string
     * sembarangan dari form mana pun.
     *
     * Key memakai GARIS BAWAH (`company_name`), bukan titik. Validator Laravel
     * menafsirkan titik sebagai akses array bersarang sehingga rule
     * 'company.name' tidak akan pernah menemukan input datar — lihat catatan
     * panjang di kepala `config/settings.php`.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
