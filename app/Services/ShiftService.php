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
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Buka shift kasir.
     *
     * Layer 4 Data Guard — baris user dikunci `lockForUpdate`, sehingga dua
     * request "buka shift" bersamaan tidak bisa sama-sama lolos cek status
     * (tidak ada shift ganda / selisih kas tercampur).
     */
    public function openShift(User $user, int $startCash): Shift
    {
        return DB::transaction(function () use ($user, $startCash): Shift {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUser->shift_status === 'open') {
                throw new RuntimeException('Shift sudah terbuka. Tutup shift terlebih dahulu.');
            }

            // Pastikan juga tidak ada baris shift terbuka yang terlewat.
            if (Shift::openFor($user->id) !== null) {
                throw new RuntimeException('Shift sudah terbuka. Tutup shift terlebih dahulu.');
            }

            $shift = Shift::create([
                'user_id' => $user->id,
                'start_time' => now(),
                'start_cash' => $startCash,
                'total_sales' => 0,
            ]);

            $lockedUser->update(['shift_status' => 'open']);

            $this->audit->log('shift.open', $shift, null, ['start_cash' => $startCash]);

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
        return DB::transaction(function () use ($user, $endCash): array {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUser->shift_status !== 'open') {
                throw new RuntimeException('Tidak ada shift terbuka untuk user ini.');
            }

            $shift = Shift::openFor($user->id);

            if ($shift === null) {
                throw new RuntimeException('Data shift aktif tidak ditemukan.');
            }

            // Kunci baris shift supaya tidak bisa ditutup dua kali bersamaan.
            $lockedShift = Shift::query()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedShift->end_time !== null) {
                throw new RuntimeException('Shift sudah pernah ditutup.');
            }

            // Hitung penjualan cash pada shift ini.
            $cashSales = (int) Transaction::query()
                ->where('shift_id', $lockedShift->id)
                ->where('status', Transaction::STATUS_COMPLETED)
                ->whereHas('payments', fn ($q) => $q->where('method', 'cash'))
                ->sum('total');

            $expectedCash = $lockedShift->start_cash + $cashSales;
            $difference = $endCash - $expectedCash;

            // Selisih disimpan permanen, bukan hanya dilempar sebagai flash
            // message — agar bisa dilaporkan per shift (lihat /shifts).
            $lockedShift->update([
                'end_time' => now(),
                'end_cash' => $endCash,
                'expected_cash' => $expectedCash,
                'difference' => $difference,
            ]);

            $lockedUser->update(['shift_status' => 'closed']);

            $this->audit->log('shift.close', $lockedShift, [
                'end_time' => null,
                'end_cash' => null,
            ], [
                'end_cash' => $endCash,
                'expected_cash' => $expectedCash,
                'difference' => $difference,
            ]);

            return [
                'shift' => $lockedShift,
                'expected_cash' => $expectedCash,
                'difference' => $difference,
            ];
        });
    }
}
