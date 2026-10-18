@extends('layouts.app')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">Audit Log</h1>
            <p class="text-sm text-base-content/60">Jejak perubahan data & aksi sensitif. Bersifat hanya-baca (append-only).</p>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-3 mb-5 bg-base-200/60 p-4 rounded-box">
        <label class="form-control">
            <span class="text-xs font-medium mb-1">Cari (aksi / IP / nama)</span>
            <input type="search" name="search" value="{{ request('search') }}"
                   placeholder="mis. void, 192.168..." class="input input-bordered w-full h-12">
        </label>

        <label class="form-control">
            <span class="text-xs font-medium mb-1">Aksi</span>
            <select name="action" class="select select-bordered w-full h-12">
                <option value="">Semua aksi</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </label>

        <label class="form-control">
            <span class="text-xs font-medium mb-1">Dari tanggal</span>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="input input-bordered w-full h-12">
        </label>

        <label class="form-control">
            <span class="text-xs font-medium mb-1">Sampai tanggal</span>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="input input-bordered w-full h-12">
        </label>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn btn-primary min-h-[48px] flex-1">Terapkan</button>
            <a href="{{ route('audit-logs.index') }}" class="btn btn-ghost min-h-[48px]">Reset</a>
        </div>
    </form>

    {{-- Daftar log --}}
    <div class="space-y-3">
        @forelse($logs as $log)
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-outline font-mono">{{ $log->action }}</span>
                                @if($log->user)
                                    <span class="font-semibold text-sm">{{ $log->user->name }}</span>
                                @else
                                    <span class="text-sm text-base-content/50">Sistem / non-HTTP</span>
                                @endif
                                <span class="text-xs text-base-content/50 font-mono">{{ $log->ip_address ?? '-' }}</span>
                            </div>

                            <div class="text-xs text-base-content/50 mt-1">
                                {{ $log->created_at?->format('d M Y H:i:s') }}
                                @if($log->auditable_type)
                                    · {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                @endif
                            </div>

                            @if($log->old_values || $log->new_values)
                                <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                                    @if($log->old_values)
                                        <div class="bg-error/10 rounded-lg p-2">
                                            <div class="font-semibold text-error mb-1">Sebelum</div>
                                            <pre class="whitespace-pre-wrap font-mono">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                    @endif
                                    @if($log->new_values)
                                        <div class="bg-success/10 rounded-lg p-2">
                                            <div class="font-semibold text-success mb-1">Sesudah</div>
                                            <pre class="whitespace-pre-wrap font-mono">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center text-base-content/50 mt-10">Belum ada log untuk filter ini.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
