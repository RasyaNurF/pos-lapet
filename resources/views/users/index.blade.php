@extends('layouts.app')

@section('content')
    <div x-data="{ openPanel: false, mode: 'create', form: {} }">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold">Karyawan</h1>
            <button type="button"
                    @click="mode = 'create'; form = { role: '', is_active: 1 }; openPanel = true"
                    class="btn btn-primary min-h-[48px] px-6">
                + Tambah Karyawan
            </button>
        </div>

        <form method="GET" class="join mb-4 w-full max-w-md">
            <input type="search" name="search" value="{{ request('search') }}"
                   placeholder="Cari nama / email / HP..."
                   class="input input-bordered join-item w-full h-12">
            <button type="submit" class="btn join-item h-12">Cari</button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @forelse($users as $employee)
                <div class="card bg-base-100 shadow-sm">
                    <div class="card-body p-4">
                        <div class="flex justify-between items-start gap-3">
                            <div class="min-w-0">
                                <div class="font-bold text-lg truncate">{{ $employee->name }}</div>
                                <div class="text-sm text-base-content/60 break-all">{{ $employee->email }}</div>
                                <div class="text-sm text-base-content/60">{{ $employee->phone ?? '-' }}</div>
                            </div>
                            <div class="flex flex-col items-end gap-1 shrink-0">
                                <span class="badge badge-outline">{{ $employee->role }}</span>
                                <span class="badge {{ $employee->is_active ? 'badge-success' : 'badge-error' }} text-white">
                                    {{ $employee->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </div>
                        </div>

                        <div class="text-xs text-base-content/50">
                            {{ $employee->address ?? 'Alamat belum diisi' }}
                        </div>
                        @if($employee->hire_date)
                            <div class="text-xs text-base-content/50">
                                Masuk: {{ $employee->hire_date->format('d/m/Y') }}
                            </div>
                        @endif

                        <div class="card-actions justify-end mt-3">
                            <button type="button"
                                    @click="mode = 'edit'; form = {{ json_encode([
                                        'id' => $employee->id,
                                        'name' => $employee->name,
                                        'email' => $employee->email,
                                        'role' => $employee->role,
                                        'phone' => $employee->phone,
                                        'address' => $employee->address,
                                        'hire_date' => optional($employee->hire_date)->format('Y-m-d'),
                                        'is_active' => $employee->is_active ? 1 : 0,
                                    ]) }}; openPanel = true"
                                    class="btn btn-sm btn-outline min-h-[44px]">
                                Edit
                            </button>
                            <button type="button"
                                    data-title="Hapus karyawan {{ $employee->name }}?"
                                    data-text="Akunnya disembunyikan dari daftar, tetapi riwayat shift dan transaksinya tetap tersimpan."
                                    data-url="{{ route('users.destroy', $employee) }}"
                                    onclick="swalConfirm({ title: this.dataset.title, text: this.dataset.text, url: this.dataset.url })"
                                    class="btn btn-sm btn-error text-white min-h-[44px]">
                                Hapus
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center text-base-content/50 mt-10">Belum ada karyawan.</div>
            @endforelse
        </div>

        <div class="mt-4">{{ $users->links() }}</div>

        {{-- Slide-over panel (AGENTS.md §4 — hindari modal bertumpuk) --}}
        <div x-show="openPanel" x-transition x-cloak class="fixed inset-0 z-40 flex" style="display: none;">
            <div class="flex-1 bg-base-300/60" @click="openPanel = false"></div>
            <div class="w-[440px] max-w-full bg-base-100 h-full shadow-2xl p-5 overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold"
                        x-text="mode === 'create' ? 'Tambah Karyawan' : 'Edit Karyawan'"></h2>
                    <button type="button" @click="openPanel = false"
                            class="btn btn-circle btn-ghost text-xl min-h-[44px] min-w-[44px]">✕</button>
                </div>

                <form :action="mode === 'create' ? '{{ route('users.store') }}' : '{{ url('/users') }}/' + form.id"
                      method="POST">
                    @csrf
                    <template x-if="mode === 'edit'"><input type="hidden" name="_method" value="PUT"></template>

                    <label class="block text-sm font-medium mb-1">Nama Lengkap</label>
                    <input type="text" name="name" :value="form.name" required maxlength="255"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" :value="form.email" required maxlength="255"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Role</label>
                    {{-- Pilihan diambil dari registry; placeholder mencegah role
                         pertama (admin) terkirim begitu saja saat form kosong. --}}
                    <select name="role" :value="form.role" required class="select select-bordered w-full mb-3 h-12">
                        <option value="" disabled :disabled="!!form.role" :selected="!form.role">— Pilih role —</option>
                        @foreach($roles as $role)
                            <option value="{{ $role }}" :selected="form.role === '{{ $role }}'">
                                {{ ucfirst($role) }}
                            </option>
                        @endforeach
                    </select>

                    <label class="block text-sm font-medium mb-1">No. HP</label>
                    <input type="tel" name="phone" :value="form.phone" maxlength="32"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Alamat</label>
                    <input type="text" name="address" :value="form.address" maxlength="255"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Tanggal Masuk</label>
                    <input type="date" name="hire_date" :value="form.hire_date"
                           class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Password</label>
                    <input type="password" name="password" autocomplete="new-password"
                           placeholder="Minimal 8 karakter"
                           class="input input-bordered w-full mb-1 h-12">
                    <p class="text-xs text-base-content/50 mb-3">
                        {{-- Kolom password selalu ikut dikirim — biarkan kosong bila tidak ingin mengganti. --}}
                        <span x-show="mode === 'edit'">Kosongkan bila tidak ingin mengganti password.</span>
                    </p>

                    <label class="block text-sm font-medium mb-1">Ulangi Password</label>
                    <input type="password" name="password_confirmation" autocomplete="new-password"
                           class="input input-bordered w-full mb-4 h-12">

                    {{-- Hidden di depan supaya checkbox bisa DIMATIKAN.
                         Tanpa ini, batal dicentang = kolom tidak terkirim sama
                         sekali dan status lama tetap menempel. --}}
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-center gap-3 cursor-pointer mb-4 min-h-[44px]">
                        <input type="checkbox" name="is_active" value="1"
                               :checked="form.is_active == 1" class="checkbox checkbox-success">
                        <span class="text-sm font-medium">Akun aktif (bisa login)</span>
                    </label>

                    @if($errors->any())
                        <div class="alert alert-error mb-3 text-sm">{{ $errors->first() }}</div>
                    @endif

                    <button type="submit"
                            class="btn btn-primary w-full min-h-[52px] text-lg font-bold">
                        Simpan
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
