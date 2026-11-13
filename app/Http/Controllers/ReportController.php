<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

        $data = $this->validatedSalesFilters($request);
        $query = $this->salesQuery($data)->latest('created_at');

        $transactions = $query->paginate(20)->withQueryString();

        // The report card summarizes the whole filtered result set, not only
        // the current page. Voided transactions remain visible in the list
        // for audit purposes, but must not count as sales or revenue.
        $summary = (clone $query)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->reorder()
            ->selectRaw(
                'COUNT(*) AS count,
                 COALESCE(SUM(total), 0) AS omzet,
                 COALESCE(SUM(discount), 0) AS discount,
                 COALESCE(SUM(tax), 0) AS tax'
            )
            ->toBase()
            ->first();

        $summary = [
            'count' => (int) $summary->count,
            'omzet' => (int) $summary->omzet,
            'discount' => (int) $summary->discount,
            'tax' => (int) $summary->tax,
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

        $data = $this->validatedSalesFilters($request);

        $fileName = 'laporan-penjualan-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($data): void {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 agar Excel membuka karakter dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");

            // Header kolom.
            fputcsv($handle, ['Invoice', 'Tanggal', 'Kasir', 'Pelanggan', 'Subtotal', 'Diskon', 'Pajak', 'Total', 'Status', 'Metode Bayar', 'Item Terjual']);

            $this->salesQuery($data)
                // A void remains visible in the on-screen report for audit,
                // but it is not a sale and must not be part of sales exports.
                ->where('status', Transaction::STATUS_COMPLETED)
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

    /**
     * Validate the same filter set for both the on-screen report and CSV.
     *
     * @return array{date_from?: string|null, date_to?: string|null, user_id?: int|string|null, method?: string|null}
     */
    private function validatedSalesFilters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'method' => ['nullable', 'in:cash,qris'],
        ]);
    }

    /**
     * Build the shared filtered query for report rows and CSV export.
     *
     * @param  array{date_from?: string|null, date_to?: string|null, user_id?: int|string|null, method?: string|null}  $filters
     * @return Builder<Transaction>
     */
    private function salesQuery(array $filters): Builder
    {
        return Transaction::query()
            ->with(['user', 'customer', 'items', 'payments'])
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            // Use the selected value: a boolean condition would pass `true`
            // into the callback and accidentally filter for ID/method `1`.
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->whereHas(
                'payments',
                fn ($payments) => $payments->where('method', $method),
            ));
    }
}
