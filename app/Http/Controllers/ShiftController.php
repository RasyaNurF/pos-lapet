<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\OpenShiftRequest;
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

        return view('shifts.close', [
            'user' => $user,
            'shift' => $user->currentShift,
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
