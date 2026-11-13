@extends('layouts.app')

@section('content')
    <div class="max-w-md mx-auto mt-10">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
                <h2 class="text-2xl font-bold mb-1">Tutup Shift</h2>

                @if($shift)
                    <div class="bg-base-200 rounded-xl p-3 mb-4 text-base">
                        <div class="flex justify-between mb-1">
                            <span>Mulai</span>
                            <span class="font-mono">{{ $shift->start_time->format('d M Y H:i') }}</span>
                        </div>
                        <div class="flex justify-between mb-1">
                            <span>Modal Awal</span>
                            <span class="font-mono">@money($shift->start_cash)</span>
                        </div>
                        <div class="flex justify-between font-bold">
                            <span>Total Sales</span>
                            <span class="font-mono text-success">@money($summary['total_sales'])</span>
                        </div>
                        <div class="flex justify-between mt-2">
                            <span>Penjualan Tunai</span>
                            <span class="font-mono">@money($summary['cash_sales'])</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Penjualan Non-tunai</span>
                            <span class="font-mono">@money($summary['non_cash_sales'])</span>
                        </div>
                        <div class="flex justify-between font-bold border-t border-base-300 mt-2 pt-2">
                            <span>Kas Seharusnya</span>
                            <span class="font-mono text-primary">@money($summary['expected_cash'])</span>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('shifts.close') }}">
                        @csrf
                        <label class="block text-sm font-medium mb-1">Kas Akhir (Rp)</label>
                        <input type="number" name="end_cash" value="{{ old('end_cash', 0) }}" min="0" required
                               class="input input-bordered w-full mb-4 h-14 text-xl text-right" placeholder="0">

                        <button type="submit"
                                class="btn btn-error w-full min-h-[56px] text-xl font-bold text-white"
                                onclick="return confirm('Tutup shift sekarang?')">
                            TUTUP SHIFT
                        </button>
                    </form>
                @else
                    <div class="alert alert-info mb-3">Tidak ada shift terbuka.</div>
                    <a href="{{ route('shifts.open.form') }}" class="btn btn-success w-full min-h-[52px]">Buka Shift</a>
                @endif

                <a href="{{ route('dashboard') }}" class="btn btn-ghost mt-2">Kembali</a>
            </div>
        </div>
    </div>
@endsection
