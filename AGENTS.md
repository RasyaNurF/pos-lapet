
---

### 2. `AGENTS.md`

```markdown
# AGENTS.md

Aturan main untuk AI coding assistant di repository ini.

---

## 1. Stack

- Backend: **Laravel 11**, PHP 8.2+
- Frontend: **Livewire 3** + Alpine.js + Tailwind CSS + DaisyUI
- Auth: Laravel Breeze (Blade/Livewire)
- RBAC: Spatie Laravel Permission 6 + registry `config/modules.php`
- Database: MySQL 8
- Alert: **SweetAlert2** (via Livewire dispatch)

---

## 2. Perintah Penting (Windows PowerShell)

| Aksi | Perintah |
|------|----------|
| Start dev | `composer run dev` |
| Migrate + seed | `php artisan migrate --seed` |
| Test | `php artisan test` |
| Format PHP | `.\vendor\bin\pint` |
| Build CSS | `npm run build` |

> ⚠️ Selalu gunakan PowerShell. Jangan gunakan `&&`, gunakan `;` atau jalankan terpisah.

---

## 3. Konvensi Backend

### Wajib
- Controller tipis. Logic di `app/Services` (misal: `PosService`, `TransactionService`).
- Validasi pakai **FormRequest**.
- Otorisasi pakai **Policy**.
- `declare(strict_types=1);` di file baru.
- Flash message: `->with('success', '...')` atau `->with('error', '...')`.
- **Kalkulasi Uang & Stok Wajib di Backend/Service** — Jangan pernah percaya total/subtotal dari frontend. (Gunakan `DB::transaction()` untuk potong stok & simpan transaksi).
- Tabel data bisnis baru **wajib soft delete** (ADR-0026).

### Jangan
- ❌ Query kompleks di controller.
- ❌ Simpan *state* keranjang (cart) langsung ke database sebelum ditekan "Bayar" (kecuali *Suspend*).
- ❌ `$request->validate()` inline.

---

## 4. Konvensi Frontend (Livewire & Tablet UI)

### Wajib (Tablet-First UX)
- **Tap Target Size**: Semua tombol interaktif (tambah qty, hapus, bayar) minimal berukuran **44x44px**.
- **Grid, bukan Tabel**: Tampilkan katalog produk dalam bentuk *Grid/Card* (Gambar, Nama, Harga). Jangan pakai tabel teks panjang.
- **Split Screen**: Halaman Kasir menggunakan layout 70% Kiri (Produk) dan 30% Kanan (Keranjang/Checkout).
- **On-Screen Numpad**: Sediakan keypad angka besar di layar untuk input pembayaran (jangan andalkan keyboard virtual OS tablet).
- **Slide-over Panel**: Form tambah/edit master data (Produk, Pelanggan) gunakan panel geser dari kanan (Alpine.js), hindari modal *pop-up* bertumpuk.
- **Livewire State**: Manfaatkan `wire:model.live` untuk pencarian produk tanpa reload.

### Jangan
- ❌ Desain yang mengandalkan *hover* (tablet tidak punya mouse).
- ❌ Tabel HTML dengan *scroll horizontal* untuk data utama.
- ❌ `console.log` di commit.

---

## 5. Alert & Notifikasi

### Wajib
- Gunakan **SweetAlert2** via dispatch Livewire (`$this->dispatch('swal:success', ...)`).
- Konfirmasi aksi destruktif (Void transaksi, hapus produk) wajib pakai `swal:confirm`.

---

## 6. RBAC & Permission

### Bentuk permission
- Format: **`<modul>.<aksi>`** — contoh: `products.view`, `sales.create`, `reports.export`.
- Aksi baku: `view`, `create`, `update`, `delete`, `print`, `export`.
- Aksi khusus POS: `void` (untuk pembatalan transaksi).

### Role
- `admin` → semua permission.
- `kasir` → `sales.view`, `sales.create`, `customers.view`, `customers.create`, `products.view`.

---

## 7. Testing

- Setiap endpoint dan Livewire action wajib punya feature test.
- Test kalkulasi keranjang: pastikan subtotal, pajak, diskon, dan kembalian akurat.
- Test pengurangan stok (pastikan stok berkurang setelah bayar, dan kembali jika transaksi di-void).