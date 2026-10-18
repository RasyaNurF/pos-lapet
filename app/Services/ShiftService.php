<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShiftService
{
    /**
     * Buka shift kasir.
     */
    public function openShift(User $user, int $startCash): Shift
    {
        if ($user->hasOpenShift()) {
            throw new RuntimeException('Shift sudah terbuka. Tutup shift terlebih dahulu.');
        }

        return DB::transaction(function () use ($user, $startCash): Shift {
            $shift = Shift::create([
                'user_id' => $user->id,
                'start_time' => now(),
                'start_cash' => $startCash,
                'total_sales' => 0,
            ]);

            $user->update(['shift_status' => 'open']);

            return $shift;
        });
    }

    /**
     * Tutup shift kasir & hitung selisih kas.
     *
     * @return array{shift: Shift, expected_cash: int, difference: int}
     */
    public function closeShift(User $user, int $endCash): array
    {
        if (! $user->hasOpenShift()) {
            throw new RuntimeException('Tidak ada shift terbuka untuk user ini.');
        }

        return DB::transaction(function () use ($user, $endCash): array {
            $shift = Shift::openFor($user->id);

            if ($shift === null) {
                throw new RuntimeException('Data shift aktif tidak ditemukan.');
            }

            // Hitung penjualan cash pada shift ini.
            $cashSales = (int) Transaction::query()
                ->where('shift_id', $shift->id)
                ->where('status', Transaction::STATUS_COMPLETED)
                ->whereHas('payments', fn ($q) => $q->where('method', 'cash'))
                ->sum('total');

            $expectedCash = $shift->start_cash + $cashSales;

            $shift->update([
                'end_time' => now(),
                'end_cash' => $endCash,
            ]);

            $user->update(['shift_status' => 'closed']);

            return [
                'shift' => $shift,
                'expected_cash' => $expectedCash,
                'difference' => $endCash - $expectedCash,
            ];
        });
    }
}
