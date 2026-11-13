<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SuspendedTransaction;
use App\Models\TransactionPayment;
use App\Services\SaleCalculator;
use App\Services\TransactionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.pos')]
class PosTerminal extends Component
{
    use WithPagination;

    /**
     * Keranjang: [variant_id => ['id', 'name', 'price', 'qty']]
     *
     * #[Locked] — Layer 1 Input Guard: klien **tidak boleh** meng-set langsung
     * (mencegah manipulasi qty negatif / harga lewat request mentah Livewire).
     * Hanya dimutasi oleh aksi server (addToCart, increaseQty, dll).
     */
    #[Locked]
    public array $cart = [];

    #[Validate('nullable|string|max:255')]
    public string $search = '';

    public ?int $category_id = null;

    public ?int $customer_id = null;

    public string $payment_method = 'cash';

    #[Validate('required|numeric|min:0')]
    public int $amount_tendered = 0;

    #[Validate('nullable|numeric|min:0')]
    public int $discount = 0;

    public string $qris_reference = '';

    /**
     * Token idempotency per aksi "Bayar" (Layer 4 Data Guard).
     *
     * Dibuat saat panel bayar dibuka dan dikirim ke backend pada checkout.
     * Klik bayar ganda (double-tap / retry jaringan) mengirim token sama sehingga
     * transaksi tidak pernah tercatat — maupun memotong stok — dua kali.
     * #[Locked] agar klien tidak bisa menggantinya untuk membatalkan proteksi.
     */
    #[Locked]
    public string $checkout_token = '';

    public bool $showPaymentPanel = false;

    public bool $showSuspendPanel = false;

    public string $suspend_label = '';

    protected $listeners = [
        'barcodeScanned' => 'addByBarcode',
        'clearCart' => 'resetCart',
    ];

    // ------------------------------------------------------------------
    // Kalkulasi (wajib server-side — SKILL.md §3.2)
    // ------------------------------------------------------------------

    #[Computed]
    public function subtotal(): int
    {
        return collect($this->cart)->sum(fn (array $item): int => $item['price'] * $item['qty']);
    }

    /**
     * PPN preview — rumus yang SAMA dengan TransactionService (SaleCalculator).
     */
    #[Computed]
    public function tax(): int
    {
        return app(SaleCalculator::class)->taxFor($this->subtotal);
    }

    #[Computed]
    public function total(): int
    {
        return app(SaleCalculator::class)->totalFor($this->subtotal, $this->tax, $this->discount);
    }

    /**
     * Tarif PPN yang berlaku sekarang, siap tampil — mis. "11" atau "11.5".
     */
    #[Computed]
    public function taxRate(): string
    {
        return SaleCalculator::formatRate(app(SaleCalculator::class)->taxRate());
    }

    #[Computed]
    public function change(): int
    {
        return max(0, $this->amount_tendered - $this->total);
    }

    #[Computed]
    public function cartCount(): int
    {
        return collect($this->cart)->sum(fn (array $item): int => $item['qty']);
    }

    // ------------------------------------------------------------------
    // Aksi tap-tap keranjang
    // ------------------------------------------------------------------

    public function addToCart(int $variantId): void
    {
        $variant = ProductVariant::query()
            ->with('product')
            ->find($variantId);

        if ($variant === null || ! $variant->product->is_active) {
            $this->dispatch('swal:error', title: 'Produk tidak ditemukan', text: 'Produk tidak aktif atau sudah dihapus.');

            return;
        }

        $currentQty = $this->cart[$variantId]['qty'] ?? 0;

        if ($variant->stock <= $currentQty) {
            $this->dispatch('swal:error', title: 'Stok tidak cukup', text: "Stok \"{$variant->display_name}\" tersisa {$variant->stock}.");

            return;
        }

        // Sebelum dimutasi — diskon default hanya diberlakukan saat keranjang
        // baru berubah dari kosong menjadi terisi.
        $wasEmpty = $this->cart === [];

        if (isset($this->cart[$variantId])) {
            $this->cart[$variantId]['qty']++;
        } else {
            $this->cart[$variantId] = [
                'id' => $variant->id,
                'name' => $variant->display_name,
                'price' => $variant->price,
                'qty' => 1,
            ];
        }

        /*
         * Pratinjau diskon default toko, hanya bila kasir belum mengisi apa pun.
         *
         * Karena syaratnya $wasEmpty, item inilah SATU-SATUNYA isi keranjang,
         * sehingga subtotal = harga x qty baris ini. Membaca $this->subtotal
         * malah berbahaya: computed-nya masih menyimpan nilai lama dari cache.
         */
        if ($wasEmpty && $this->discount === 0) {
            $this->discount = app(SaleCalculator::class)
                ->defaultDiscountFor($variant->price * $this->cart[$variantId]['qty']);
        }

        $this->resetComputedCache();
    }

    public function increaseQty(int $variantId): void
    {
        $this->addToCart($variantId);
    }

    public function decreaseQty(int $variantId): void
    {
        if (! isset($this->cart[$variantId])) {
            return;
        }

        if ($this->cart[$variantId]['qty'] > 1) {
            $this->cart[$variantId]['qty']--;
        } else {
            unset($this->cart[$variantId]);
            $this->cart = $this->cart; // reindex
        }

        $this->resetComputedCache();
    }

    public function removeFromCart(int $variantId): void
    {
        unset($this->cart[$variantId]);
        $this->cart = $this->cart;
        $this->resetComputedCache();
    }

    public function resetCart(): void
    {
        $this->cart = [];
        $this->discount = 0;
        $this->customer_id = null;
        $this->amount_tendered = 0;
        $this->qris_reference = '';
        $this->checkout_token = '';
        $this->showPaymentPanel = false;
        $this->resetComputedCache();
        $this->resetPage();
    }

    /**
     * Dipanggil saat barcode scanner mengirim SKU + Enter.
     */
    public function addByBarcode(string $barcode): void
    {
        $variant = ProductVariant::query()
            ->where('sku', $barcode)
            ->first();

        if ($variant === null) {
            $this->dispatch('swal:error', title: 'Barcode tidak dikenal', text: "SKU \"{$barcode}\" tidak ditemukan.");

            return;
        }

        $this->addToCart($variant->id);
    }

    // ------------------------------------------------------------------
    // Diskon & pelanggan
    // ------------------------------------------------------------------

    public function updatedDiscount(): void
    {
        if ($this->discount < 0) {
            $this->discount = 0;
        }

        $this->resetComputedCache();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // ------------------------------------------------------------------
    // Pembayaran
    // ------------------------------------------------------------------

    public function openPaymentPanel(): void
    {
        if ($this->cart === []) {
            $this->dispatch('swal:error', title: 'Keranjang kosong', text: 'Tambahkan produk terlebih dahulu.');

            return;
        }

        // QRIS: uang diterima dianggap lunas senilai total belanja.
        if ($this->payment_method === TransactionPayment::METHOD_QRIS) {
            $this->amount_tendered = $this->total;
            $this->resetComputedCache();
        }

        // Token baru untuk setiap sesi pembayaran (idempotency).
        $this->checkout_token = (string) Str::uuid();

        $this->showPaymentPanel = true;
    }

    /**
     * Saat metode bayar diganti: QRIS → langsung lunas (tanpa input uang diterima).
     */
    public function updatedPaymentMethod(string $value): void
    {
        if ($value === TransactionPayment::METHOD_QRIS) {
            $this->amount_tendered = $this->total;
            $this->resetComputedCache();
        }
    }

    public function closePaymentPanel(): void
    {
        $this->showPaymentPanel = false;
        $this->checkout_token = '';
    }

    public function quickTender(int $amount): void
    {
        $this->amount_tendered = $amount;
        $this->resetComputedCache();
    }

    public function checkout(TransactionService $service): void
    {
        // Sinkronkan uang diterima untuk QRIS (lunas = total).
        if ($this->payment_method === TransactionPayment::METHOD_QRIS) {
            $this->amount_tendered = $this->total;
            $this->resetComputedCache();
        }

        $this->validate([
            'payment_method' => 'required|in:cash,qris',
            'amount_tendered' => 'required|numeric|min:'.$this->total,
            'discount' => 'nullable|numeric|min:0',
        ], [
            'amount_tendered.min' => 'Uang diterima kurang dari total belanja.',
        ]);

        try {
            // Idempotency: pakai token sesi bayar; bila kosong (mis. dipanggil
            // tanpa panel), buat baru — dua klik pada sesi sama tetap satu transaksi.
            if ($this->checkout_token === '') {
                $this->checkout_token = (string) Str::uuid();
            }

            $transaction = $service->processTransaction(
                user: auth()->user(),
                cart: $this->cart,
                customerId: $this->customer_id,
                paymentMethod: $this->payment_method,
                amountTendered: $this->amount_tendered,
                discount: $this->discount,
                clientToken: $this->checkout_token,
            );

            $this->resetCart();

            $this->dispatch('swal:success', title: 'Berhasil!', text: "Transaksi {$transaction->invoice_no} disimpan.");
            $this->dispatch('print-receipt', transaction_id: $transaction->id);
        } catch (\Exception $e) {
            $this->dispatch('swal:error', title: 'Gagal', text: $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Suspend / Resume (ADR-0004)
    // ------------------------------------------------------------------

    public function openSuspendPanel(): void
    {
        if ($this->cart === []) {
            $this->dispatch('swal:error', title: 'Keranjang kosong', text: 'Tidak ada yang perlu di-hold.');

            return;
        }

        $this->showSuspendPanel = true;
    }

    public function suspendTransaction(TransactionService $service): void
    {
        try {
            $service->suspend(
                user: auth()->user(),
                cart: $this->cart,
                customerId: $this->customer_id,
                discount: $this->discount,
                label: $this->suspend_label !== '' ? $this->suspend_label : null,
            );

            $this->resetCart();
            $this->suspend_label = '';
            $this->showSuspendPanel = false;

            $this->dispatch('swal:success', title: 'Ditahan', text: 'Transaksi disimpan ke daftar tunda.');
        } catch (\Exception $e) {
            $this->dispatch('swal:error', title: 'Gagal', text: $e->getMessage());
        }
    }

    public function resumeTransaction(int $suspendedId, TransactionService $service): void
    {
        $suspended = SuspendedTransaction::query()
            ->whereKey($suspendedId)
            ->where('user_id', auth()->id())
            ->first();

        if ($suspended === null) {
            $this->dispatch('swal:error', title: 'Gagal', text: 'Data tunda tidak ditemukan.');

            return;
        }

        if ($this->cart !== []) {
            $this->dispatch('swal:error', title: 'Keranjang tidak kosong', text: 'Selesaikan atau kosongkan keranjang sebelum resume.');

            return;
        }

        $this->cart = $service->resume($suspended);
        $this->customer_id = $suspended->customer_id;
        $this->discount = $suspended->discount;

        $this->resetComputedCache();

        $this->dispatch('swal:success', title: 'Dipulihkan', text: 'Keranjang dipulihkan dari daftar tunda.');
    }

    public function deleteSuspended(int $suspendedId): void
    {
        SuspendedTransaction::query()
            ->whereKey($suspendedId)
            ->where('user_id', auth()->id())
            ->delete();

        $this->dispatch('swal:success', title: 'Dihapus', text: 'Daftar tunda dihapus.');
    }

    // ------------------------------------------------------------------

    /**
     * Reset cache computed property Livewire.
     */
    private function resetComputedCache(): void
    {
        unset(
            $this->subtotal,
            $this->tax,
            $this->taxRate,
            $this->total,
            $this->change,
            $this->cartCount,
        );
    }

    public function render(): View
    {
        $term = trim($this->search);

        $products = Product::query()
            ->with(['variants' => fn ($q) => $q->where('stock', '>=', 0)])
            ->where('is_active', true)
            ->when($this->category_id !== null, fn ($q) => $q->where('category_id', $this->category_id))
            ->when($term !== '', fn ($q) => $q->search($term))
            ->orderBy('name')
            ->paginate(12);

        return view('livewire.pos-terminal', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'suspended' => SuspendedTransaction::query()
                ->where('user_id', auth()->id())
                ->latest()
                ->get(),
        ]);
    }
}
