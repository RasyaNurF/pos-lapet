<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\OpenShiftRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shiftService,
    ) {}

    /**
     * Riwayat shift + rekonsiliasi kas.
     *
     * Sengaja memakai `reports.view`, bukan `shifts.view`. Kasir memang punya
     * `shifts.view` untuk membuka/menutup shift miliknya sendiri, tetapi halaman
     * ini memuat selisih kas SELURUH kasir — itu wewenang pengawas (admin),
     * bukan operator kasir.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()?->can('reports.view') === true, 403);

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        $query = Shift::query()
            ->with('user')
            ->when($data['date_from'] ?? null, fn ($q, $v) => $q->whereDate('start_time', '>=', $v))
            ->when($data['date_to'] ?? null, fn ($q, $v) => $q->whereDate('start_time', '<=', $v))
            ->when($data['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when(($data['status'] ?? null) === 'open', fn ($q) => $q->whereNull('end_time'))
            ->when(($data['status'] ?? null) === 'closed', fn ($q) => $q->whereNotNull('end_time'))
            ->latest('start_time');

        // Di-clone SEBELUM paginate: ringkasan harus mencakup SELURUH hasil
        // filter, bukan hanya 20 baris halaman yang sedang tampil.
        $summary = (clone $query)
            ->selectRaw(
                'COUNT(*) AS shifts,
                 COALESCE(SUM(start_cash), 0) AS total_start_cash,
                 COALESCE(SUM(expected_cash), 0) AS total_expected_cash,
                 COALESCE(SUM(end_cash), 0) AS total_end_cash,
                 COALESCE(SUM(difference), 0) AS total_difference,
                 SUM(CASE WHEN difference IS NOT NULL AND difference <> 0 THEN 1 ELSE 0 END) AS mismatched'
            )
            ->toBase()
            ->first();

        return view('shifts.index', [
            'shifts' => $query->paginate(20)->withQueryString(),
            'summary' => [
                'shifts' => (int) $summary->shifts,
                'total_start_cash' => (int) $summary->total_start_cash,
                'total_expected_cash' => (int) $summary->total_expected_cash,
                'total_end_cash' => (int) $summary->total_end_cash,
                'total_difference' => (int) $summary->total_difference,
                'mismatched' => (int) $summary->mismatched,
            ],
            'cashiers' => User::orderBy('name')->get(['id', 'name']),
            'filters' => $data,
        ]);
    }

    /**
     * Form buka shift.
     */
    public function openForm(Request $request): View
    {
        return view('shifts.open', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Proses buka shift.
     */
    public function open(OpenShiftRequest $request): RedirectResponse
    {
        try {
            $this->shiftService->openShift($request->user(), (int) $request->validated('start_cash'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos')->with('success', 'Shift berhasil dibuka. Selamat bertugas!');
    }

    /**
     * Form tutup shift.
     */
    public function closeForm(Request $request): View
    {
        $user = $request->user();
        $shift = $user->currentShift;

        return view('shifts.close', [
            'user' => $user,
            'shift' => $shift,
            'summary' => $shift !== null ? $this->shiftService->closingSummary($shift) : null,
        ]);
    }

    /**
     * Proses tutup shift + hitung selisih kas.
     */
    public function close(CloseShiftRequest $request): RedirectResponse
    {
        try {
            $result = $this->shiftService->closeShift($request->user(), (int) $request->validated('end_cash'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $difference = $result['difference'];
        $message = $difference === 0
            ? 'Shift ditutup. Kas sesuai.'
            : 'Shift ditutup. Selisih kas: Rp '.number_format($difference, 0, ',', '.').' ('.($difference < 0 ? 'kurang' : 'lebih').').';

        return redirect()->route('dashboard')->with($difference === 0 ? 'success' : 'error', $message);
    }
}
