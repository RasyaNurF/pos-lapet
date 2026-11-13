@extends('layouts.app')

@section('content')
    <div x-data="{ openPanel: false, mode: 'create', form: {} }">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold">Produk</h1>
            <button @click="mode = 'create'; form = {}; openPanel = true" class="btn btn-primary min-h-[48px] px-6">
                + Tambah Produk
            </button>
        </div>

        {{-- pencarian --}}
        <form method="GET" class="join mb-4 w-full max-w-md">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama / SKU..."
                   class="input input-bordered join-item w-full h-12">
            <button type="submit" class="btn join-item h-12">Cari</button>
        </form>

        {{-- grid card (bukan tabel — tablet UX) --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @forelse($products as $product)
                <div class="card bg-base-100 shadow-sm">
                    <div class="card-body p-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-bold text-lg">{{ $product->name }}</div>
                                <div class="text-sm text-base-content/60">SKU: {{ $product->sku }}</div>
                                <div class="text-sm text-base-content/60">{{ $product->category?->name ?? 'Tanpa kategori' }}</div>
                            </div>
                            <span class="badge {{ $product->is_active ? 'badge-success' : 'badge-ghost' }}">
                                {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </div>

                        <div class="mt-2 text-sm">
                            @foreach($product->variants as $variant)
                                <div class="flex justify-between items-center border-t border-base-200 py-1">
                                    <span>{{ $variant->name }} — <span class="font-mono">@money($variant->price)</span></span>
                                    <form method="POST" action="{{ route('products.variant.stock', $variant) }}" class="flex items-center gap-1">
                                        @csrf @method('PUT')
                                        <input type="number" name="stock" value="{{ $variant->stock }}" min="0"
                                               class="input input-bordered input-xs w-20 text-right">
                                        <button type="submit" class="btn btn-xs">Stok</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>

                        <div class="card-actions justify-end mt-3">
                            <button @click="mode = 'edit'; form = @js($product->only(['id', 'sku', 'name', 'category_id', 'base_price', 'base_cost', 'stock_alert', 'is_active']) + ['variants' => $product->variants->map(fn ($variant) => $variant->only(['id', 'name', 'price', 'cost']))->values()]); openPanel = true"
                                    class="btn btn-sm btn-outline min-h-[44px]">Edit</button>
                            <form method="POST" action="{{ route('products.destroy', $product) }}"
                                  onsubmit="swalConfirm({ title: this.dataset.title, url: this.action }); return false;" data-title="Hapus produk {{ $product->name }}?">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-error text-white min-h-[44px]">Hapus</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center text-base-content/50 mt-10">Belum ada produk.</div>
            @endforelse
        </div>

        <div class="mt-4">{{ $products->links() }}</div>

        {{-- Slide-over panel (AGENTS.md §4) --}}
        <div x-show="openPanel" x-transition x-cloak class="fixed inset-0 z-40 flex" style="display: none;">
            <div class="flex-1 bg-base-300/60" @click="openPanel = false"></div>
            <div class="w-[440px] max-w-full bg-base-100 h-full shadow-2xl p-5 overflow-y-auto">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold" x-text="mode === 'create' ? 'Tambah Produk' : 'Edit Produk'"></h2>
                    <button @click="openPanel = false" class="btn btn-circle btn-ghost text-xl">✕</button>
                </div>

                <form :action="mode === 'create' ? '{{ route('products.store') }}' : '{{ url('/products') }}/' + form.id" method="POST">
                    @csrf
                    <template x-if="mode === 'edit'"><input type="hidden" name="_method" value="PUT"></template>

                    <label class="block text-sm font-medium mb-1">SKU</label>
                    <input type="text" name="sku" :value="form.sku" required class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Nama Produk</label>
                    <input type="text" name="name" :value="form.name" required class="input input-bordered w-full mb-3 h-12">

                    <label class="block text-sm font-medium mb-1">Kategori</label>
                    <select name="category_id" class="select select-bordered w-full mb-3 h-12">
                        <option value="">— Tanpa kategori —</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" x-selected="form.category_id === {{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>

                    <label class="block text-sm font-medium mb-1">Harga Jual Dasar (Rp)</label>
                    <input type="number" name="base_price" :value="form.base_price" min="0" required class="input input-bordered w-full mb-3 h-12 text-right">
                    <p class="text-xs text-base-content/60 -mt-2 mb-3">Harga dasar untuk produk/varian baru. Atur harga varian yang sudah ada di bawah.</p>

                    <label class="block text-sm font-medium mb-1">Harga Modal (Rp)</label>
                    <input type="number" name="base_cost" :value="form.base_cost" min="0" required class="input input-bordered w-full mb-3 h-12 text-right">

                    <template x-if="mode === 'edit' && form.variants?.length">
                        <div class="border-t border-base-300 pt-3 mb-3">
                            <h3 class="font-semibold mb-2">Harga Tiap Varian</h3>
                            <template x-for="variant in form.variants" :key="variant.id">
                                <div class="rounded-lg border border-base-300 p-3 mb-2">
                                    <div class="font-medium mb-2" x-text="variant.name"></div>
                                    <label class="block text-xs mb-1">Harga jual (Rp)</label>
                                    <input type="number" min="0" required class="input input-bordered w-full h-11 mb-2 text-right"
                                           :name="'variants[' + variant.id + '][price]'" :value="variant.price">
                                    <label class="block text-xs mb-1">Harga modal (Rp)</label>
                                    <input type="number" min="0" required class="input input-bordered w-full h-11 text-right"
                                           :name="'variants[' + variant.id + '][cost]'" :value="variant.cost">
                                </div>
                            </template>
                        </div>
                    </template>

                    <label class="block text-sm font-medium mb-1">Batas Stok Alert</label>
                    <input type="number" name="stock_alert" :value="form.stock_alert ?? 5" min="0" required class="input input-bordered w-full mb-3 h-12 text-right">

                    <template x-if="mode === 'create'">
                        <div>
                            <label class="block text-sm font-medium mb-1">Nama Varian (opsional, mis. 1kg)</label>
                            <input type="text" name="variant_name" class="input input-bordered w-full mb-3 h-12">

                            <label class="block text-sm font-medium mb-1">Stok Awal Varian</label>
                            <input type="number" name="variant_stock" value="0" min="0" class="input input-bordered w-full mb-3 h-12 text-right">
                        </div>
                    </template>

                    <label class="label cursor-pointer justify-start gap-3 mb-4">
                        <input type="checkbox" name="is_active" value="1" :checked="mode === 'create' || form.is_active" class="checkbox">
                        <span class="label-text">Produk aktif (ditampilkan di POS)</span>
                    </label>

                    @if($errors->any())
                        <div class="alert alert-error mb-3 text-sm">{{ $errors->first() }}</div>
                    @endif

                    <button type="submit" class="btn btn-primary w-full min-h-[52px] text-lg font-bold">
                        Simpan
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
