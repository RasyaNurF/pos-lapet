<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom rekonsiliasi kas pada shift.
     *
     * Sebelumnya selisih kas hanya muncul SEKALI sebagai flash message saat tutup
     * shift, lalu hilang — tidak bisa ditelusuri lagi. `expected_cash` &
     * `difference` disimpan permanen supaya riwayat selisih dapat diaudit per
     * shift, bukan sekadar dibaca dari JSON `audit_logs`.
     *
     * `difference` memakai `integer` SIGNED karena boleh negatif (kas kurang).
     * Kolom uang lain di tabel ini `unsigned`, jadi ini satu-satunya yang sah
     * bernilai minus.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->unsignedBigInteger('expected_cash')->nullable()->after('start_cash');
            $table->integer('difference')->nullable()->after('end_cash');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropColumn(['expected_cash', 'difference']);
        });
    }

    /**
     * Hitung ulang rekonsiliasi untuk shift yang sudah pernah ditutup.
     *
     * Rumusnya harus identik dengan `ShiftService::closeShift()` supaya angka
     * historis konsisten dengan shift baru. `total_sales` TIDAK bisa dipakai di
     * sini karena kolom itu mencakup pembayaran QRIS, sedangkan kas yang
     * diharapkan hanya penjualan tunai.
     *
     * Shift yang masih berjalan (`end_cash` NULL) dibiarkan NULL — akan terisi
     * otomatis saat ditutup.
     */
    private function backfill(): void
    {
        $shifts = DB::table('shifts')
            ->whereNotNull('end_cash')
            ->get(['id', 'start_cash', 'end_cash']);

        foreach ($shifts as $shift) {
            $cashSales = (int) DB::table('transactions')
                ->where('shift_id', $shift->id)
                ->where('status', 'completed')
                ->whereIn('id', function ($query): void {
                    $query->select('transaction_id')
                        ->from('transaction_payments')
                        ->where('method', 'cash');
                })
                ->sum('total');

            $expectedCash = (int) $shift->start_cash + $cashSales;

            DB::table('shifts')->where('id', $shift->id)->update([
                'expected_cash' => $expectedCash,
                'difference' => (int) $shift->end_cash - $expectedCash,
            ]);
        }
    }
};
