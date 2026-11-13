@extends('layouts.app')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Laporan Penjualan</h1>
        @if(auth()->user()->can('reports.export'))
            <a href="{{ route('reports.export', request()->query()) }}" class="btn btn-success min-h-[48px] px-6">
                ⬇ Export CSV
            </a>
        @endif
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap gap-2 items-end mb-4">
        <div>
            <label class="block text-sm mb-1">Dari</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="input input-bordered h-12">
        </div>
        <div>
            <label class="block text-sm mb-1">Sampai</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="input input-bordered h-12">
        </div>
        <div>
            <label class="block text-sm mb-1">Kasir</label>
            <select name="user_id" class="select select-bordered h-12">
                <option value="">Semua</option>
                @foreach($cashiers as $cashier)
                    <option value="{{ $cashier->id }}" @selected(($filters['user_id'] ?? null) == $cashier->id)>{{ $cashier->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm mb-1">Metode</label>
            <select name="method" class="select select-bordered h-12">
                <option value="">Semua</option>
                @foreach(\App\Models\TransactionPayment::METHODS as $method => $label)
                    <option value="{{ $method }}" @selected(($filters['method'] ?? null) === $method)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary min-h-[48px]">Terapkan</button>
    </form>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Transaksi</div>
            <div class="stat-value text-xl">{{ $summary['count'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Omzet</div>
            <div class="stat-value text-success text-xl">@money($summary['omzet'])</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Diskon</div>
            <div class="stat-value text-warning text-xl">@money($summary['discount'])</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Pajak</div>
            <div class="stat-value text-xl">@money($summary['tax'])</div>
        </div>
    </div>

    {{-- Detail item terjual --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($transactions as $transaction)
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body p-4">
                    <div class="flex justify-between items-start">
                        <div class="font-bold font-mono">{{ $transaction->invoice_no }}</div>
                        <span class="badge {{ $transaction->isVoided() ? 'badge-error' : 'badge-success' }}">
                            {{ $transaction->isVoided() ? 'VOID' : 'OK' }}
                        </span>
                    </div>
                    <div class="text-sm text-base-content/60">
                        {{ $transaction->created_at->format('d M Y H:i') }} — {{ $transaction->user?->name }}
                    </div>
                    <ul class="text-sm mt-1">
                        @foreach($transaction->items as $item)
                            <li class="flex justify-between">
                                <span>{{ $item->qty }}x {{ $item->product_name }}</span>
                                <span class="font-mono">@money($item->subtotal)</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="border-t border-base-200 mt-2 pt-2 flex justify-between font-bold">
                        <span>Total</span>
                        <span class="font-mono">@money($transaction->total)</span>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full text-center text-base-content/50 mt-10">Tidak ada transaksi untuk filter ini.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $transactions->links() }}</div>
@endsection
