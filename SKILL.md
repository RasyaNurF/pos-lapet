---
name: generate-pos-terminal
description: Generate halaman Livewire kasir (POS Terminal) dengan layout split-screen tablet, grid produk, keranjang interaktif, dan on-screen numpad. Digunakan saat membuat antarmuka transaksi utama.
---

# Skill: POS Terminal (Tablet Tap-Tap)

Dasar membangun antarmuka kasir mengikuti `AGENTS.md` §4 dan `DESIGN.md` ADR-0002.

## 1. Kapan Skill Ini Dipakai
- Membuat halaman utama kasir (`/pos`).
- Menambahkan fitur keranjang (tambah, kurang, hapus, kalkulasi pajak/diskon).
- Membuat panel pembayaran (Cash/QRIS) dan hitung kembalian.

## 2. Aturan UI (Tablet Friendly)
- ❌ Jangan gunakan `<table>` untuk katalog produk. Gunakan `<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">`.
- Ukuran minimal tombol = `min-h-[56px]` (Tailwind).
- Font minimal `text-lg` untuk nama produk dan harga.
- Area keranjang (cart) menggunakan `overflow-y-auto` agar tetap di layar.

## 3. Struktur Livewire Component

### 3.1 Properties
```php
public $search = '';
public $cart = []; // format: [variant_id => ['id', 'name', 'price', 'qty']]
public $customer_id = null;
public $payment_method = 'cash';
public $amount_tendered = 0;
public $discount = 0;

protected $listeners = ['barcodeScanned' => 'addByBarcode'];
3.2 Kalkulasi (Wajib di Server)
php
public function getSubtotalProperty() {
    return collect($this->cart)->sum(fn($item) => $item['price'] * $item['qty']);
}

public function getTaxProperty() {
    return round($this->subtotal * 0.11); // PPN 11%
}

public function getTotalProperty() {
    return $this->subtotal + $this->tax - $this->discount;
}

public function getChangeProperty() {
    return max(0, $this->amount_tendered - $this->total);
}
3.3 Aksi Tap-Tap
php
public function addToCart($variantId) {
    // Cek stok
    $variant = ProductVariant::findOrFail($variantId);
    
    if (isset($this->cart[$variantId])) {
        $this->cart[$variantId]['qty']++;
    } else {
        $this->cart[$variantId] = [
            'id' => $variant->id,
            'name' => $variant->product->name . ' - ' . $variant->name,
            'price' => $variant->price,
            'qty' => 1,
        ];
    }
}

public function decreaseQty($variantId) {
    if ($this->cart[$variantId]['qty'] > 1) {
        $this->cart[$variantId]['qty']--;
    } else {
        unset($this->cart[$variantId]);
    }
}
3.4 Tombol Bayar (Proses ke Service)
php
public function checkout(TransactionService $service) {
    $this->validate([
        'amount_tendered' => 'required|numeric|min:' . $this->total,
        'payment_method' => 'required|in:cash,qris'
    ]);

    try {
        $transaction = $service->processTransaction(
            user: auth()->user(),
            cart: $this->cart,
            customerId: $this->customer_id,
            paymentMethod: $this->payment_method,
            amountTendered: $this->amount_tendered,
            discount: $this->discount
        );

        $this->dispatch('swal:success', ['title' => 'Berhasil!', 'text' => 'Transaksi disimpan.']);
        $this->dispatch('print-receipt', ['transaction_id' => $transaction->id]);
        
        $this->reset(['cart', 'amount_tendered', 'discount', 'customer_id']);
    } catch (\Exception $e) {
        $this->dispatch('swal:error', ['title' => 'Gagal', 'text' => $e->getMessage()]);
    }
}
4. Blade View (Tailwind)
html
<div class="flex h-screen bg-gray-100 overflow-hidden">
    <!-- KIRI: Katalog -->
    <div class="w-2/3 p-4 flex flex-col h-full">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Scan Barcode / Cari..." class="w-full text-xl p-4 rounded-xl mb-4 shadow-sm">
        
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 overflow-y-auto">
            @foreach($products as $product)
                <button wire:click="addToCart({{ $product->id }})" class="bg-white p-4 rounded-xl shadow hover:bg-blue-50 active:scale-95 transition-transform flex flex-col items-center min-h-[150px]">
                    <span class="font-bold text-lg text-center">{{ $product->name }}</span>
                    <span class="text-blue-600 font-semibold mt-2">Rp {{ number_format($product->price, 0, ',', '.') }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <!-- KANAN: Keranjang & Bayar -->
    <div class="w-1/3 bg-white border-l flex flex-col h-full shadow-2xl">
        <div class="p-4 flex-1 overflow-y-auto">
            @forelse($cart as $id => $item)
                <div class="flex justify-between items-center mb-3 bg-gray-50 p-3 rounded-lg">
                    <div>
                        <div class="font-bold">{{ $item['name'] }}</div>
                        <div class="text-sm text-gray-500">Rp {{ number_format($item['price'], 0, ',', '.') }}</div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button wire:click="decreaseQty({{ $id }})" class="w-10 h-10 bg-red-100 text-red-600 rounded-lg font-bold text-xl">-</button>
                        <span class="font-bold text-lg w-8 text-center">{{ $item['qty'] }}</span>
                        <button wire:click="addToCart({{ $id }})" class="w-10 h-10 bg-green-100 text-green-600 rounded-lg font-bold text-xl">+</button>
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-400 mt-10">Keranjang Kosong</div>
            @endforelse
        </div>

        <!-- Total & Numpad -->
        <div class="p-4 bg-gray-50 border-t">
            <div class="flex justify-between text-xl font-bold mb-4">
                <span>Total</span>
                <span>Rp {{ number_format($this->total, 0, ',', '.') }}</span>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Uang Diterima</label>
                <input type="number" wire:model.live="amount_tendered" class="w-full text-2xl p-4 rounded-xl border-2 border-blue-500 text-right font-mono" placeholder="0">
            </div>

            <button wire:click="checkout" class="w-full bg-green-600 text-white rounded-xl font-bold text-2xl py-4 shadow-lg active:scale-95 transition-transform">
                BAYAR
            </button>
        </div>
    </div>
</div>
5. Checklist Verifikasi
□ Tampilan diuji di resolusi Tablet (min 768px width).
□ Kalkulasi total (subtotal, pajak, diskon) benar dan dihitung di backend.
□ Tombol + / - mengubah kuantitas tanpa reload halaman.
□ Validasi uang kurang (amount_tendered < total) memunculkan error.
□ Stok berkurang otomatis setelah bayar.
□ php artisan test lulus untuk test kalkulasi keranjang.
