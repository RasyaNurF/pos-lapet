@extends('layouts.app')

@section('content')
    <h1 class="text-2xl font-bold mb-4">Dashboard</h1>

    {{-- Ringkasan hari ini --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Omzet Hari Ini</div>
            <div class="stat-value text-success text-2xl">@money($todayOmzet)</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Total Transaksi</div>
            <div class="stat-value text-2xl">{{ $todayTransactions }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Produk Stok Menipis</div>
            <div class="stat-value text-warning text-2xl">{{ $lowStockCount }}</div>
            <div class="stat-desc"><a href="{{ route('reports.stock') }}" class="link">Lihat laporan stok</a></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Grafik penjualan 7 hari --}}
        <div class="bg-base-100 rounded-xl shadow-sm p-4">
            <h2 class="font-bold text-lg mb-3">Penjualan 7 Hari Terakhir</h2>
            <div class="flex items-end gap-2 h-48">
                @foreach($weeklySales as $day)
                    <div class="flex-1 flex flex-col items-center gap-1">
                        <div class="text-xs font-mono">@money($day['omzet'])</div>
                        <div class="w-full bg-primary rounded-t-lg"
                             style="height: {{ max(4, (int) round($day['omzet'] / $maxWeekly * 100)) }}%"></div>
                        <div class="text-xs text-base-content/60">{{ $day['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Produk terlaris --}}
        <div class="bg-base-100 rounded-xl shadow-sm p-4">
            <h2 class="font-bold text-lg mb-3">Produk Terlaris Hari Ini</h2>
            @if($topProducts->isEmpty())
                <div class="text-base-content/50">Belum ada penjualan hari ini.</div>
            @else
                <table class="table table-sm">
                    <thead>
                        <tr><th>Produk</th><th class="text-right">Qty</th><th class="text-right">Pendapatan</th></tr>
                    </thead>
                    <tbody>
                        @foreach($topProducts as $product)
                            <tr>
                                <td>{{ $product->product_name }}</td>
                                <td class="text-right font-mono">{{ $product->total_qty }}</td>
                                <td class="text-right font-mono">@money($product->total_revenue)</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Aksi cepat shift --}}
    <div class="mt-6 flex gap-3">
        @if(auth()->user()->hasOpenShift())
            <a href="{{ route('shifts.close.form') }}" class="btn btn-error btn-lg text-white min-h-[56px]">🔒 Tutup Shift</a>
        @else
            <a href="{{ route('shifts.open.form') }}" class="btn btn-success btn-lg min-h-[56px]">🔓 Buka Shift</a>
        @endif
        <a href="{{ route('pos') }}" class="btn btn-primary btn-lg min-h-[56px]">🧾 Mulai Kasir</a>
    </div>
@endsection
