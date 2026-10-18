<div class="flex h-full bg-base-200 overflow-hidden">

    {{-- ============================================================ --}}
    {{-- KIRI (70%): Katalog produk --}}
    {{-- ============================================================ --}}
    <div class="w-7/12 xl:w-8/12 p-4 flex flex-col h-full">

        {{-- Pencarian / Barcode --}}
        <div class="join w-full mb-3">
            <input
                wire:model.live.debounce.300ms="search"
                type="search"
                placeholder="Scan barcode atau cari produk..."
                class="input input-bordered join-item w-full text-xl h-14"
                autofocus
            >
            <button
                wire:click="resetCart"
                wire:confirm="Kosongkan seluruh keranjang?"
                class="btn btn-warning join-item h-14 px-6 text-lg"
            >
                Kosongkan
            </button>
        </div>

        {{-- Tab kategori --}}
        <div class="flex gap-2 overflow-x-auto pb-2 mb-3">
            <button
                wire:click="$set('category_id', null)"
                class="btn btn-sm whitespace-nowrap {{ $category_id === null ? 'btn-primary' : 'btn-ghost border border-base-300' }}"
            >
                Semua
            </button>
            @foreach($categories as $category)
                <button
                    wire:click="$set('category_id', {{ $category->id }})"
                    class="btn btn-sm whitespace-nowrap {{ $category_id === $category->id ? 'btn-primary' : 'btn-ghost border border-base-300' }}"
                >
                    {{ $category->name }}
                </button>
            @endforeach
        </div>

        {{-- Grid produk (bukan tabel — AGENTS.md §4) --}}
        <div class="flex-1 overflow-y-auto pr-1">
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                @forelse($products as $product)
                    @foreach($product->variants as $variant)
                        <button
                            wire:click="addToCart({{ $variant->id }})"
                            @disabled($variant->stock < 1)
                            wire:key="variant-{{ $variant->id }}"
                            class="bg-base-100 p-4 rounded-xl shadow-sm border border-base-300
                                   active:scale-95 transition-transform flex flex-col items-center
                                   min-h-[150px] disabled:opacity-40"
                        >
                            @if($product->image)
                                <img src="{{ $product->image }}" alt="{{ $product->name }}" class="w-16 h-16 object-cover rounded-lg mb-2">
                            @else
                                <div class="w-16 h-16 rounded-lg bg-primary/10 text-primary flex items-center justify-center text-2xl font-bold mb-2">
                                    {{ strtoupper(substr($product->name, 0, 1)) }}
                                </div>
                            @endif
                            <span class="font-bold text-base text-center leading-tight">{{ $product->name }}</span>
                            <span class="text-xs text-base-content/60">{{ $variant->name }}</span>
                            <span class="text-primary font-semibold mt-1 text-lg">@money($variant->price)</span>
                            <span class="text-xs mt-1 {{ $variant->stock <= $product->stock_alert ? 'text-error font-bold' : 'text-base-content/50' }}">
                                Stok: {{ $variant->stock }}
                            </span>
                        </button>
                    @endforeach
                @empty
                    <div class="col-span-full text-center text-base-content/50 mt-10 text-lg">
                        Produk tidak ditemukan.
                    </div>
                @endforelse
            </div>

            <div class="mt-4 flex justify-center">
                {{ $products->links() }}
            </div>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- KANAN (30%): Keranjang & Pembayaran --}}
    {{-- ============================================================ --}}
    <div class="w-5/12 xl:w-4/12 bg-base-100 border-l border-base-300 flex flex-col h-full shadow-2xl">

        {{-- Daftar tunda (suspend) --}}
        @if($suspended->isNotEmpty())
            <div class="bg-warning/10 border-b border-warning/30 p-2">
                <div class="text-xs font-bold text-warning-content/80 mb-1 uppercase tracking-wide">Transaksi Ditahan</div>
                <div class="flex gap-2 overflow-x-auto pb-1">
                    @foreach($suspended as $hold)
                        <div wire:key="hold-{{ $hold->id }}" class="bg-base-100 border border-warning/40 rounded-lg p-2 min-w-[140px] flex-shrink-0">
                            <div class="text-sm font-semibold truncate">{{ $hold->label ?? 'Tanpa label' }}</div>
                            <div class="text-xs text-base-content/60">{{ collect($hold->cart)->sum('qty') }} item</div>
                            <div class="flex gap-1 mt-1">
                                <button wire:click="resumeTransaction({{ $hold->id }})" class="btn btn-xs btn-primary flex-1 min-h-[32px]">Lanjut</button>
                                <button wire:click="deleteSuspended({{ $hold->id }})" wire:confirm="Hapus transaksi tunda ini?" class="btn btn-xs btn-error text-white min-h-[32px]">✕</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Header keranjang --}}
        <div class="p-3 border-b border-base-300 flex items-center justify-between">
            <div>
                <div class="font-bold text-lg">Keranjang</div>
                <div class="text-sm text-base-content/60">{{ $this->cartCount }} item</div>
            </div>
            <button wire:click="openSuspendPanel" class="btn btn-warning btn-sm min-h-[44px] px-4">
                Tahan
            </button>
        </div>

        {{-- Item keranjang --}}
        <div class="flex-1 overflow-y-auto p-3">
            @forelse($cart as $id => $item)
                <div wire:key="cart-{{ $id }}" class="flex justify-between items-center mb-2 bg-base-200 p-3 rounded-lg">
                    <div class="min-w-0 flex-1 mr-2">
                        <div class="font-bold truncate">{{ $item['name'] }}</div>
                        <div class="text-sm text-base-content/60">@money($item['price']) / item</div>
                        <div class="text-sm font-semibold text-primary">@money($item['price'] * $item['qty'])</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="decreaseQty({{ $id }})" class="w-11 h-11 bg-error/15 text-error rounded-lg font-bold text-2xl leading-none">−</button>
                        <span class="font-bold text-lg w-8 text-center">{{ $item['qty'] }}</span>
                        <button wire:click="increaseQty({{ $id }})" class="w-11 h-11 bg-success/15 text-success rounded-lg font-bold text-2xl leading-none">+</button>
                        <button wire:click="removeFromCart({{ $id }})" class="w-11 h-11 bg-base-300 rounded-lg text-lg leading-none" title="Hapus">🗑</button>
                    </div>
                </div>
            @empty
                <div class="text-center text-base-content/40 mt-10 text-lg">Keranjang Kosong</div>
            @endforelse
        </div>

        {{-- Ringkasan & bayar --}}
        <div class="p-3 bg-base-200 border-t border-base-300">
            <label class="block text-sm font-medium mb-1">Pelanggan</label>
            <select wire:model.live="customer_id" class="select select-bordered w-full mb-2 h-12">
                <option value="">Umum</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->name }} {{ $customer->phone ? "({$customer->phone})" : '' }}</option>
                @endforeach
            </select>

            <label class="block text-sm font-medium mb-1">Diskon (Rp)</label>
            <input type="number" wire:model.live="discount" min="0" class="input input-bordered w-full mb-2 h-12 text-right" placeholder="0">

            <div class="flex justify-between text-base mb-1">
                <span>Subtotal</span>
                <span class="font-mono">@money($this->subtotal)</span>
            </div>
            <div class="flex justify-between text-base mb-1">
                <span>Pajak ({{ $this->taxRate }}%)</span>
                <span class="font-mono">@money($this->tax)</span>
            </div>
            <div class="flex justify-between text-xl font-bold mb-3 border-t border-base-300 pt-2">
                <span>TOTAL</span>
                <span class="font-mono text-primary">@money($this->total)</span>
            </div>

            <button
                wire:click="openPaymentPanel"
                @disabled($cart === [])
                class="w-full bg-success text-success-content rounded-xl font-bold text-2xl py-4 shadow-lg
                       active:scale-95 transition-transform disabled:opacity-40 min-h-[64px]"
            >
                BAYAR
            </button>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- Slide-over: Panel Pembayaran + Numpad on-screen --}}
    {{-- ============================================================ --}}
    @if($showPaymentPanel)
        <div
            x-data
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            class="fixed inset-0 z-40 flex"
        >
            <div class="flex-1 bg-base-300/60" wire:click="closePaymentPanel"></div>
            <div class="w-[420px] max-w-full bg-base-100 h-full shadow-2xl flex flex-col p-4 overflow-y-auto">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-2xl font-bold">Pembayaran</h2>
                    <button wire:click="closePaymentPanel" class="btn btn-circle btn-ghost text-2xl">✕</button>
                </div>

                <div class="bg-primary/10 rounded-xl p-3 mb-3 text-center">
                    <div class="text-sm text-base-content/60">Total Tagihan</div>
                    <div class="text-3xl font-bold font-mono text-primary">@money($this->total)</div>
                </div>

                {{-- Metode bayar --}}
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <button
                        wire:click="$set('payment_method', 'cash')"
                        class="btn min-h-[56px] text-lg {{ $payment_method === 'cash' ? 'btn-primary' : 'btn-outline' }}"
                    >
                        💵 Tunai
                    </button>
                    <button
                        wire:click="$set('payment_method', 'qris')"
                        class="btn min-h-[56px] text-lg {{ $payment_method === 'qris' ? 'btn-primary' : 'btn-outline' }}"
                    >
                        📱 QRIS
                    </button>
                </div>

                @if($payment_method === 'qris')
                    <label class="block text-sm font-medium mb-1">No. Referensi QRIS</label>
                    <input type="text" wire:model="qris_reference" class="input input-bordered w-full mb-3 h-14 text-lg" placeholder="Scan / masukkan ref...">
                @endif

                @if($payment_method === 'cash')
                    {{-- Uang diterima + Numpad (khusus tunai) --}}
                    <label class="block text-sm font-medium mb-1">Uang Diterima</label>
                    <input
                        type="text"
                        inputmode="none"
                        readonly
                        wire:model="amount_tendered"
                        class="input input-bordered w-full text-2xl p-4 text-right font-mono mb-2 h-14 bg-base-100"
                        placeholder="0"
                    >

                    <div class="grid grid-cols-3 gap-2 mb-3">
                        @foreach([10000, 20000, 50000, 100000] as $quick)
                            <button wire:click="quickTender({{ $quick }})" class="btn btn-outline btn-sm min-h-[44px] text-sm col-span-1">
                                @money($quick)
                            </button>
                        @endforeach
                        <button wire:click="$set('amount_tendered', {{ $this->total }})" class="btn btn-outline btn-sm min-h-[44px] text-sm col-span-2">
                            Uang Pas
                        </button>
                        <button wire:click="$set('amount_tendered', 0)" class="btn btn-outline btn-sm min-h-[44px] text-sm">
                            C
                        </button>
                    </div>

                    {{-- Numpad besar (AGENTS.md §4) --}}
                    <div class="grid grid-cols-3 gap-2 mb-3">
                        @foreach(['1', '2', '3', '4', '5', '6', '7', '8', '9', '000', '0', '←'] as $key)
                            <button
                                @if($key !== '←')
                                    x-on:click="$wire.set('amount_tendered', Number(($wire.amount_tendered || 0).toString() + '{{ $key }}'))"
                                @else
                                    x-on:click="$wire.set('amount_tendered', Math.floor(($wire.amount_tendered || 0) / 10))"
                                @endif
                                class="btn min-h-[64px] text-2xl font-bold {{ $key === '←' ? 'btn-error btn-outline' : 'btn-outline' }}"
                            >
                                {{ $key }}
                            </button>
                        @endforeach
                    </div>

                    @error('amount_tendered')
                        <div class="alert alert-error mb-2 text-sm">{{ $message }}</div>
                    @enderror

                    <div class="flex justify-between text-xl font-bold mb-3">
                        <span>Kembalian</span>
                        <span class="font-mono {{ $this->change < 0 ? 'text-error' : 'text-success' }}">@money($this->change)</span>
                    </div>
                @else
                    {{-- QRIS: lunas otomatis senilai total, tanpa input uang diterima --}}
                    <div class="bg-info/10 border border-info/30 rounded-xl p-4 mb-3 text-center">
                        <div class="text-sm text-base-content/60 mb-1">Tagihan Dilunasi via QRIS</div>
                        <div class="text-2xl font-bold font-mono text-info">@money($this->total)</div>
                        <div class="text-xs text-base-content/60 mt-1">Pastikan pembayaran berhasil discan sebelum menutup panel.</div>
                    </div>
                @endif

                <button
                    wire:click="checkout"
                    wire:loading.attr="disabled"
                    class="w-full bg-success text-success-content rounded-xl font-bold text-2xl py-4 shadow-lg
                           active:scale-95 transition-transform min-h-[64px]"
                >
                    <span wire:loading.remove wire:target="checkout">BAYAR SEKARANG</span>
                    <span wire:loading wire:target="checkout">Memproses...</span>
                </button>
            </div>
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- Slide-over: Panel Suspend (Hold) --}}
    {{-- ============================================================ --}}
    @if($showSuspendPanel)
        <div
            x-data
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            class="fixed inset-0 z-40 flex"
        >
            <div class="flex-1 bg-base-300/60" wire:click="$set('showSuspendPanel', false)"></div>
            <div class="w-[380px] max-w-full bg-base-100 h-full shadow-2xl flex flex-col p-4">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-2xl font-bold">Tahan Transaksi</h2>
                    <button wire:click="$set('showSuspendPanel', false)" class="btn btn-circle btn-ghost text-2xl">✕</button>
                </div>
                <p class="text-base-content/70 mb-3">Simpan keranjang saat ini agar bisa dilanjutkan nanti.</p>
                <label class="block text-sm font-medium mb-1">Label (opsional)</label>
                <input type="text" wire:model="suspend_label" class="input input-bordered w-full mb-4 h-14 text-lg" placeholder="Contoh: Meja 3 / Budi">
                <button wire:click="suspendTransaction" class="btn btn-warning min-h-[56px] text-lg font-bold">
                    Tahan Sekarang
                </button>
            </div>
        </div>
    @endif
</div>
