<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Laporan penjualan dengan filter tanggal / kasir / metode.
     */
    public function sales(Request $request): View
    {
        abort_unless($request->user()?->can('reports.view') === true, 403);

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'method' => ['nullable', 'in:cash,qris'],
        ]);

        $query = Transaction::query()
            ->with(['user', 'customer', 'items', 'payments'])
            ->when($data['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when(($data['user_id'] ?? null) !== null, fn ($q, $v) => $q->where('user_id', $v))
            ->when(($data['method'] ?? null) !== null, fn ($q, $v) => $q->whereHas('payments', fn ($pq) => $pq->where('method', $v)))
            ->latest('created_at');

        $transactions = $query->paginate(20)->withQueryString();

        $summary = [
            'count' => $transactions->count(),
            'omzet' => (int) $transactions->sum('total'),
            'discount' => (int) $transactions->sum('discount'),
            'tax' => (int) $transactions->sum('tax'),
        ];

        return view('reports.sales', [
            'transactions' => $transactions,
            'summary' => $summary,
            'cashiers' => User::orderBy('name')->get(['id', 'name']),
            'filters' => $data,
        ]);
    }

    /**
     * Laporan stok menipis (di bawah batas minimum).
     */
    public function stock(): View
    {
        abort_unless(auth()->user()?->can('reports.view') === true, 403);

        $variants = ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->whereColumn('stock', '<=', DB::raw('(SELECT stock_alert FROM products WHERE products.id = product_variants.product_id)'))
            ->orderBy('stock')
            ->get();

        return view('reports.stock', compact('variants'));
    }

    /**
     * Export CSV penjualan (streaming + BOM UTF-8 — plan.md TAHAP 4).
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.export') === true, 403);

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $fileName = 'laporan-penjualan-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($data): void {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 agar Excel membuka karakter dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");

            // Header kolom.
            fputcsv($handle, ['Invoice', 'Tanggal', 'Kasir', 'Pelanggan', 'Subtotal (IDR)', 'Diskon (IDR)', 'Pajak (IDR)', 'Total (IDR)', 'Status', 'Metode Bayar', 'Item Terjual']);

            Transaction::query()
                ->with(['user', 'customer', 'items', 'payments'])
                ->when($data['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                ->when($data['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
                ->orderBy('created_at')
                ->chunk(200, function ($transactions) use ($handle): void {
                    foreach ($transactions as $transaction) {
                        fputcsv($handle, [
                            $transaction->invoice_no,
                            $transaction->created_at?->format('Y-m-d H:i'),
                            $transaction->user?->name,
                            $transaction->customer?->name ?? 'Umum',
                            $transaction->subtotal,
                            $transaction->discount,
                            $transaction->tax,
                            $transaction->total,
                            $transaction->status,
                            $transaction->payments->pluck('method')->implode('/'),
                            $transaction->items->sum('qty'),
                        ]);
                    }
                });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
