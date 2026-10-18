# DESIGN.md

Arsitektur dan keputusan desain project ini.

---

## 1. Tujuan Project

- Problem: Aplikasi POS lama (LA PET GALLERY) berbasis UI desktop (Bootstrap klasik) yang sulit digunakan di tablet kasir. Kasir membutuhkan antarmuka yang cepat, besar, dan bisa di-*tap*.
- Target user: `admin` (mengelola stok & laporan), `kasir` (operasional harian).
- Fitur utama: POS Terminal (Tablet), Katalog Varian, Split Payment, Manajemen Stok, Laporan.

---

## 2. Arsitektur
Tablet Browser (Livewire + Alpine + Tailwind)
↓ HTTP (Livewire Payloads)
Laravel 11
Livewire Component → Service Layer → Eloquent
↓
Policy, Event, DB Transaction
↓
MySQL 8

text

---

## 3. Keputusan Arsitektur (ADRs)

### ADR-0001: Penggunaan Livewire 3 vs Inertia/React
- **Alasan:** Untuk aplikasi POS, kecepatan *development* dan interaksi tanpa *page-reload* sangat krusial. Livewire 3 memungkinkan state keranjang (cart) terkelola di sisi server tanpa perlu menulis API endpoint, sangat efisien untuk transaksi.
- **Trade-off:** Kurang cocok jika UI sangat kompleks (seperti drag-and-drop heavy), tapi untuk kasir yang sifatnya *tap-tap* (grid & list), Livewire sangat optimal.

### ADR-0002: Tablet-First UI/UX
- **Alasan:** Kasir menggunakan tablet sentuh. UI harus mencegah *mis-tap* dan mempercepat alur kerja.
- **Keputusan:**
  - Layout Kasir: Kiri (Katalog Grid 70%), Kanan (Keranjang & Numpad 30%).
  - Tombol "BAYAR" berukuran ekstra besar.
  - Menghilangkan modal *pop-up* untuk form master data, diganti *slide-over panel* (Alpine.js).
- **Trade-off:** Membutuhkan styling Tailwind yang lebih kustom (tidak bisa asal pakai template admin standar).

### ADR-0003: Skema Database POS (Snapshot Data)
- **Alasan:** Harga dan nama produk bisa berubah sewaktu-waktu. Jika tidak disimpan sebagai *snapshot* di tabel transaksi, struk lama akan berubah harganya saat produk di-update.
- **Keputusan:**
  - Tabel `transaction_items` menyimpan `product_name`, `price`, dan `cost` saat transaksi terjadi.
  - Menggunakan *atomic transaction* (`DB::transaction`) saat memotong stok untuk mencegah *race condition* (dua kasir jual barang terakhir bersamaan).

### ADR-0004: Penanganan "Suspend" (Hold) Transaksi
- **Alasan:** Pelanggan sering lupa membawa uang atau batal beli di tengah jalan.
- **Keputusan:**
  - Keranjang tidak disimpan ke database selama berjalan.
  - Saat tombol "Suspend" ditekan, data keranjang (JSON) disimpan ke tabel `suspended_transactions` beserta ID Kasir.
  - Saat di-resume, data ditarik kembali ke state keranjang Livewire.

---

## 4. Struktur Database

**users:** id, name, email, password, role, shift_status (open/closed), timestamps
**categories:** id, name, timestamps
**products:** id, sku, name, category_id, base_price, base_cost, stock_alert, is_active, timestamps
**product_variants:** id, product_id, name (1kg, 5kg), sku, price, cost, stock, timestamps
**customers:** id, name, phone, email, points, timestamps
**transactions:** id, invoice_no, customer_id, user_id, shift_id, subtotal, discount, tax, total, status (completed/voided), notes, created_at
**transaction_items:** id, transaction_id, variant_id, product_name (snapshot), price (snapshot), qty, subtotal
**transaction_payments:** id, transaction_id, method (cash/qris), amount, reference_no (jika qris)
**shifts:** id, user_id, start_time, end_time, start_cash, end_cash, total_sales

---

## 5. Frontend & UI Standar

- **Library UI:** DaisyUI (Tailwind component) karena menyediakan tombol besar & input yang mudah di-style.
- **State Keranjang:** Disimpan dalam *public property* Livewire (`public array $cart = [];`). Interaksi tambah/kurang barang memanggil method Livewire (`addToCart`, `increaseQty`, `decreaseQty`) yang langsung menghitung ulang total secara *server-side*.
- **Print Struk:** Menggunakan CSS `@media print` khusus (lebar 58mm/80mm). Tidak menggunakan window print biasa, melainkan modal khusus yang di-trigger setelah bayar.