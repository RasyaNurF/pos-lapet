<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data karyawan & status aktif.
     *
     * Kolom `users.role` dan `shift_status` sudah ada (migrasi 070000); ini
     * melengkapinya menjadi data karyawan yang layak ditampilkan admin.
     *
     * `deleted_at` ikut ditambahkan karena menghapus kasir secara permanen
     * akan menghapus namanya dari riwayat shift, transaksi, dan audit —
     * jejak sejarah toko jadi tidak bisa ditelusuri siapa yang melayani.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('email');
            $table->text('address')->nullable()->after('phone');
            $table->date('hire_date')->nullable()->after('address');
            $table->boolean('is_active')->default(true)->after('role');
            $table->softDeletes()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'address', 'hire_date', 'is_active', 'deleted_at']);
        });
    }
};
