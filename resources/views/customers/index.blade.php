@extends('layouts.app')

@section('content')
    <div x-data="{ openPanel: false, mode: 'create', form: {} }">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold">Pelanggan</h1>
            <button @click="mode = 'create'; form = {}; openPanel = true" class="btn btn-primary min-h-[48px] px-6">
                + Tambah Pelanggan
            </button>
        </div>

        <form method="GET" class="join mb-4 w-full max-w-md">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama / HP..."
                   class="input input-bordered join-item w-full h-12">
            <button type="submit" class="btn join-item h-12">Cari</button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @forelse($customers as $customer)
                <div class="card bg-base-100 shadow-sm">
                    <div class="card-body p-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-bold text-lg">{{ $customer->name }}</div>
                                <div class="text-sm text-base-content/60">{{ $customer->phone ?? '-' }}</div>
                                <div class="text-sm text-base-content/60">{{ $customer->email ?? '' }}</div>
                            </div>
                            <span class="badge badge-primary">{{ $customer->points }} poin</span>
                        </div>
                        <div class="card-actions justify-end mt-3">
                            <button @click="mode = 'edit'; form = {{ json_encode($customer->only(['id', 'name', 'phone', 'email'])) }}; openPanel = true"
                                    class="btn btn-sm btn-outline min-h-[44px]">Edit</button>
                            <form method="POST" action="{{ route('customers.destroy', $customer) }}"
                                  onsubmit="return confirm('Hapus pelanggan {{ $customer->name }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-error text-white min-h-[44px]">Hapus</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center text-base-content/50 mt-10">Belum ada pelanggan.</div>
            @endforelse
        </div>

        <div class="mt-4">{{ $customers->links() }}</div>

        {{-- Slide-over panel --}}
        <div x-show="openPanel" x-transition x-cloak class="fixed inset-0 z-40 flex" style="display: none;">
            <div class="flex-1 bg-base-300/60" @click="openPanel = false"></div>
            <div class="w-[400px] max-w-full bg-base-100 h-full shadow-2xl p-5">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold" x-text="mode === 'create' ? 'Tambah Pelanggan' : 'Edit Pelanggan'"></h2>
                    <button @click="openPanel = false" class="btn btn-circle btn-ghost text-xl">✕</button>
                </div>

                <form :action="mode === 'create' ? '{{ route('customers.store') }}' : '{{ url('/customers') }}/' + form.id" method="POST">
                    @csrf
                    <template x-if="mode === 'edit'"><input type="hidden" name="_method" value="PUT"></template>

                    <label class="block text-sm font-medium mb-1">Nama</label>
                    <input type="text" name="name" :value="form.name" required class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">No. HP</label>
                    <input type="text" name="phone" :value="form.phone" class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" :value="form.email" class="input input-bordered w-full mb-4 h-12">

                    @if($errors->any())
                        <div class="alert alert-error mb-3 text-sm">{{ $errors->first() }}</div>
                    @endif

                    <button type="submit" class="btn btn-primary w-full min-h-[52px] text-lg font-bold">Simpan</button>
                </form>
            </div>
        </div>
    </div>
@endsection
