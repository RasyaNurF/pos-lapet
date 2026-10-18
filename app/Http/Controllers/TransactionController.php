<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\VoidTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactionService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Transaction::class);

        $transactions = Transaction::query()
            ->with(['user', 'customer', 'payments'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('transactions.index', compact('transactions'));
    }

    /**
     * Struk (print modal, lewat JS — plan.md TAHAP 3).
     */
    public function receipt(Transaction $transaction): View
    {
        $this->authorize('print', $transaction);

        return view('transactions.receipt', [
            'transaction' => $transaction->load(['items', 'payments', 'customer', 'user']),
        ]);
    }

    /**
     * Void transaksi: kembalikan stok & tandai status.
     */
    public function void(VoidTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('void', $transaction);

        try {
            $this->transactionService->voidTransaction(
                transaction: $transaction,
                by: $request->user(),
                reason: $request->validated('reason'),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Transaksi {$transaction->invoice_no} di-void. Stok dikembalikan.");
    }
}
