@extends('layouts.app')

@section('content')
    <div class="max-w-md mx-auto mt-10">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
                <h2 class="text-2xl font-bold mb-1">Buka Shift</h2>
                <p class="text-base-content/60 mb-4">Hitung modal awal kas sebelum mulai bertugas.</p>

                @if(session('error'))
                    <div class="alert alert-error mb-3">{{ session('error') }}</div>
                @endif

                <form method="POST" action="{{ route('shifts.open') }}">
                    @csrf
                    <label class="block text-sm font-medium mb-1">Modal Awal Kas (Rp)</label>
                    <input type="number" name="start_cash" value="{{ old('start_cash', 0) }}" min="0" required
                           class="input input-bordered w-full mb-4 h-14 text-xl text-right" placeholder="0">

                    <button type="submit" class="btn btn-success w-full min-h-[56px] text-xl font-bold">
                        BUKA SHIFT
                    </button>
                </form>

                <a href="{{ route('dashboard') }}" class="btn btn-ghost mt-2">Kembali</a>
            </div>
        </div>
    </div>
@endsection
