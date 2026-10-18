@extends('layouts.app')

@section('content')
    <div class="max-w-2xl">
        <h1 class="text-2xl font-bold mb-4">Profil Saya</h1>

        @if(session('success'))
            <div class="alert alert-success mb-4 text-sm">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-error mb-4 text-sm">{{ session('error') }}</div>
        @endif

        <div class="card bg-base-100 shadow-sm">
            <div class="card-body p-5">
                <h2 class="text-lg font-bold">Data Diri</h2>

                {{-- Tiga baris ini urusan admin — ditampilkan sebagai informasi,
                     bukan input, supaya tidak ada jalan untuk mengubahnya sendiri. --}}
                <div class="flex flex-wrap gap-2 mb-4">
                    <span class="badge badge-outline">{{ $profile->role }}</span>
                    <span class="badge {{ $profile->is_active ? 'badge-success' : 'badge-error' }} text-white">
                        {{ $profile->is_active ? 'Aktif' : 'Nonaktif' }}
                    </span>
                    @if($profile->hire_date)
                        <span class="badge badge-ghost">Masuk {{ $profile->hire_date->format('d/m/Y') }}</span>
                    @endif
                </div>

                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf @method('PUT')

                    @if($errors->any())
                        <div class="alert alert-error mb-4 text-sm">
                            <ul class="list-disc pl-4">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <label class="block text-sm font-medium mb-1">Nama Lengkap</label>
                    <input type="text" name="name" value="{{ old('name', $profile->name) }}" required
                           maxlength="255" class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', $profile->email) }}" required
                           maxlength="255" class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">No. HP</label>
                    <input type="tel" name="phone" value="{{ old('phone', $profile->phone) }}" maxlength="32"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Alamat</label>
                    <input type="text" name="address" value="{{ old('address', $profile->address) }}" maxlength="255"
                           class="input input-bordered w-full mb-5 h-12">

                    <div class="divider text-sm font-semibold">Ganti Password</div>

                    <p class="text-xs text-base-content/60 mb-3">
                        Kosongkan ketiga kolom di bawah bila tidak ingin mengganti password.
                    </p>

                    <label class="block text-sm font-medium mb-1">Password Saat Ini</label>
                    <input type="password" name="current_password" autocomplete="current-password"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Password Baru</label>
                    <input type="password" name="password" autocomplete="new-password"
                           placeholder="Minimal 8 karakter"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Ulangi Password Baru</label>
                    <input type="password" name="password_confirmation" autocomplete="new-password"
                           class="input input-bordered w-full mb-5 h-12">

                    <button type="submit" class="btn btn-primary w-full min-h-[52px] text-lg font-bold">
                        Simpan Perubahan
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
