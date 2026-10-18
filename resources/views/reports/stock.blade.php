@extends('layouts.app')

@section('content')
    <h1 class="text-2xl font-bold mb-4">Laporan Stok Menipis</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($variants as $variant)
            <div class="card bg-base-100 shadow-sm border-l-4 border-error">
                <div class="card-body p-4">
                    <div class="font-bold text-lg">{{ $variant->product?->name }} — {{ $variant->name }}</div>
                    <div class="text-sm text-base-content/60">SKU: {{ $variant->sku }}</div>
                    <div class="flex gap-4 mt-1">
                        <div>
                            <div class="text-xs text-base-content/60">Stok</div>
                            <div class="font-bold text-error text-xl">{{ $variant->stock }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-base-content/60">Batas Minimum</div>
                            <div class="font-bold text-xl">{{ $variant->product?->stock_alert }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full text-center text-base-content/50 mt-10">
                ✅ Semua stok aman di atas batas minimum.
            </div>
        @endforelse
    </div>
@endsection
