<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $today = Carbon::today();

        $todayStats = Transaction::query()
            ->where('status', Transaction::STATUS_COMPLETED)
            ->whereDate('created_at', $today)
            ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(total), 0) as omzet')
            ->first();

        $topProducts = Transaction::query()
            ->join('transaction_items', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.status', Transaction::STATUS_COMPLETED)
            ->whereDate('transactions.created_at', $today)
            ->groupBy('transaction_items.product_name')
            ->selectRaw('transaction_items.product_name, SUM(transaction_items.qty) as total_qty, SUM(transaction_items.subtotal) as total_revenue')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        // Grafik penjualan 7 hari terakhir (bar chart sederhana).
        $weeklySales = collect(range(6, 0))->map(function (int $daysAgo) use ($today): array {
            $date = $today->copy()->subDays($daysAgo);

            $omzet = (int) Transaction::query()
                ->where('status', Transaction::STATUS_COMPLETED)
                ->whereDate('created_at', $date)
                ->sum('total');

            return [
                'label' => $date->locale('id')->isoFormat('ddd'),
                'omzet' => $omzet,
            ];
        });

        $maxWeekly = max(1, $weeklySales->max('omzet'));

        $lowStockCount = ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->whereColumn('stock', '<=', DB::raw('(SELECT stock_alert FROM products WHERE products.id = product_variants.product_id)'))
            ->count();

        return view('dashboard', [
            'todayOmzet' => (int) $todayStats->omzet,
            'todayTransactions' => (int) $todayStats->total_transactions,
            'topProducts' => $topProducts,
            'weeklySales' => $weeklySales,
            'maxWeekly' => $maxWeekly,
            'lowStockCount' => $lowStockCount,
        ]);
    }
}
