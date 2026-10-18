@extends('layouts.app')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Riwayat Shift &amp; Selisih Kas</h1>
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
            <label class="block text-sm mb-1">Status</label>
            <select name="status" class="select select-bordered h-12">
                <option value="">Semua</option>
                <option value="open" @selected(($filters['status'] ?? null) === 'open')>Masih berjalan</option>
                <option value="closed" @selected(($filters['status'] ?? null) === 'closed')>Sudah ditutup</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary min-h-[48px]">Terapkan</button>
    </form>

    {{-- Ringkasan --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Shift</div>
            <div class="stat-value text-xl">{{ $summary['shifts'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Kas awal</div>
            <div class="stat-value text-xl">Rp {{ number_format($summary['total_start_cash'], 0, ',', '.') }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Kas seharusnya</div>
            <div class="stat-value text-xl">Rp {{ number_format($summary['total_expected_cash'], 0, ',', '.') }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Kas akhir</div>
            <div class="stat-value text-xl">Rp {{ number_format($summary['total_end_cash'], 0, ',', '.') }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Selisih bersih</div>
            <div class="stat-value text-xl {{ $summary['total_difference'] === 0 ? 'text-success' : 'text-error' }}">
                Rp {{ number_format($summary['total_difference'], 0, ',', '.') }}
            </div>
            <div class="stat-desc">
                {{ $summary['total_difference'] === 0 ? 'Kas seluruh shift sesuai' : ($summary['total_difference'] < 0 ? 'Kurang' : 'Lebih') }}
            </div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm">
            <div class="stat-title">Shift bermasalah</div>
            <div class="stat-value text-error text-xl">{{ $summary['mismatched'] }}</div>
            <div class="stat-desc">Selisihnya bukan nol</div>
        </div>
    </div>

    {{-- Detail per shift --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($shifts as $shift)
            <div class="card bg-base-100 shadow-sm {{ $shift->difference !== null && $shift->difference !== 0 ? 'border-2 border-error/40' : '' }}">
                <div class="card-body p-4">
                    <div class="flex justify-between items-start">
                        <div class="font-bold">{{ $shift->user?->name ?? '—' }}</div>
                        @if($shift->isOpen())
                            <span class="badge badge-info">Masih berjalan</span>
                        @elseif($shift->difference === 0)
                            <span class="badge badge-success">Kas sesuai</span>
                        @else
                            <span class="badge badge-error">Selisih</span>
                        @endif
                    </div>

                    <div class="text-sm text-base-content/60">
                        Buka {{ $shift->start_time->format('d M Y H:i') }}
                        @if($shift->end_time)
                            — Tutup {{ $shift->end_time->format('d M Y H:i') }}
                        @endif
                    </div>

                    <ul class="text-sm mt-1 space-y-1">
                        <li class="flex justify-between">
                            <span>Kas awal</span>
                            <span class="font-mono">Rp {{ number_format($shift->start_cash, 0, ',', '.') }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Penjualan tunai</span>
                            <span class="font-mono">
                                @if($shift->expected_cash !== null)
                                    Rp {{ number_format($shift->expected_cash - $shift->start_cash, 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </span>
                        </li>
                        <li class="flex justify-between">
                            <span>Kas seharusnya</span>
                            <span class="font-mono">
                                @if($shift->expected_cash !== null)
                                    Rp {{ number_format($shift->expected_cash, 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </span>
                        </li>
                        <li class="flex justify-between">
                            <span>Kas akhir</span>
                            <span class="font-mono">
                                @if($shift->end_cash !== null)
                                    Rp {{ number_format($shift->end_cash, 0, ',', '.') }}
                                @else
                                    —
                                @endif
                            </span>
                        </li>
                    </ul>

                    <div class="border-t border-base-200 mt-2 pt-2 flex justify-between items-center font-bold">
                        <span>Selisih</span>
                        @if($shift->difference === null)
                            <span class="text-base-content/50">Belum dihitung</span>
                        @elseif($shift->difference === 0)
                            <span class="font-mono text-success">Rp 0</span>
                        @elseif($shift->difference < 0)
                            <span class="font-mono text-error">Kurang Rp {{ number_format(abs($shift->difference), 0, ',', '.') }}</span>
                        @else
                            <span class="font-mono text-warning">Lebih Rp {{ number_format($shift->difference, 0, ',', '.') }}</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full text-center text-base-content/50 mt-10">Tidak ada shift untuk filter ini.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $shifts->links() }}</div>
@endsection
