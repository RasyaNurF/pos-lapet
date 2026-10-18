> **Status pengerjaan**
>
> - [x] TAHAP 1 — Database & Fondasi (selesai)
> - [x] TAHAP 2 — POS Terminal (selesai)
> - [x] TAHAP 3 — Cetak Struk & Shift Kasir (selesai)
> - [x] TAHAP 4 — Laporan & Export (selesai)
> - [x] TAHAP 5 — Testing, Polish & Build Assets (selesai; 37 test hijau, pint bersih, `npm run build` sukses)
> - [x] TAHAP 6 — Minimum Enterprise Guard (selesai; 71 test hijau, 2 skip MySQL-only, pint bersih)
> - [x] TAHAP 7 — Navigasi & Tutup Shift (selesai; POS bukan lagi jalan buntu)
> - [x] TAHAP 8 — Laporan Selisih Kas (selesai; 87 test hijau, 2 skip MySQL-only)
> - [ ] TAHAP 9 — Store Config (rencana; fondasi pengaturan toko — mengganti hardcode nama/PPN/mata uang)
> - [ ] TAHAP 10 — Modul Karyawan (rencana; CRUD user + role manajer)
> - [ ] Deployment (VPS/Nginx) — belum dikerjakan
>
> Login: `admin@lapet.test` / `kasir@lapet.test`, password `password`.

🗄️ TAHAP 1: Database & Fondasi (Master Data) ✅
Tujuan: Membangun skema database dan CRUD dasar untuk data yang tidak sering berubah.

Buat Migration & Model

Migration dasar: categories, products, product_variants, customers, users (tambahkan field role atau gunakan Spatie).

Jalankan: php artisan make:model Product -m, dll.

Catatan: Pastikan tabel transaksi tidak dibuat dulu untuk menghindari dependency error.

Setup RBAC (Role & Permission)

Buat config/modules.php sesuai standar (berisi modul: products, pos, reports, dll).

Buat PermissionSeeder dan RoleSeeder (Admin & Kasir).

Jalankan: php artisan db:seed --class=PermissionSeeder

Buat CRUD Master (Backend & UI)

Fokus ke halaman Admin (bukan POS).

Buat ProductController, CustomerController.

Gunakan Slide-over Panel (Alpine.js) untuk form tambah/edit (sesuai standar Tablet UX di AGENTS.md, hindari modal bertumpuk).

Test: php artisan test untuk memastikan CRUD berjalan.

🛒 TAHAP 2: POS Terminal (Core Feature) ✅
Tujuan: Membangun antarmuka utama kasir (Livewire Component) yang Tablet-Friendly (Split-screen, Grid, Tap-Tap).

Buat Livewire Component PosTerminal

Perintah: php artisan make:livewire PosTerminal

Implementasi State Keranjang (Cart)

Buat public properties: $cart = [], $search = '', $amount_tendered = 0.

Buat computed properties: $subtotal, $tax, $total.

Implementasi UI Split-Screen (Tailwind)

Layout: Kiri (Grid Produk), Kanan (Keranjang & Numpad).

Tambahkan tombol besar + dan - pada keranjang.

Integrasi Barcode Scanner & Search

Gunakan wire:model.live.debounce.300ms="search" pada input pencarian agar saat barcode scanner USB/BT memencet enter, produk langsung muncul.

Logika Checkout (Simpan ke Database)

Saat tombol "BAYAR" ditekan:

Buat TransactionService.

Gunakan DB::transaction untuk menyimpan header transaksi, detail item, memotong stok (product_variants.stock), dan mencatat metode pembayaran.

Hitung kembalian di backend, validasi jika uang kurang.

Kosongkan keranjang setelah sukses.

Fitur Suspend (Hold)

Buat migration suspended_transactions.

Simpan array cart ke JSON saat tombol "Suspend" ditekan. Tarik kembali saat di-resume.

🖨️ TAHAP 3: Cetak Struk & Shift Kasir ✅
Tujuan: Menyelesaikan alur transaksi dengan cetak struk thermal dan penutupan shift.

Cetak Struk (Print Modal)

Buat modal yang muncul setelah transaksi sukses.

Gunakan CSS @media print ukuran 58mm/80mm.

Tambahkan tombol window.print() (tanpa library tambahan, sesuai AGENTS.md).

Manajemen Shift

Kasir wajib "Buka Shift" (input modal awal) sebelum bisa akses /pos.

Kasir wajib "Tutup Shift" (input kas akhir) saat selesai, sistem hitung selisih kas.

Void Transaksi

Fitur untuk membatalkan transaksi yang salah (dengan konfirmasi swal:confirm).

Pastikan stok produk kembali bertambah saat transaksi di-void.

📊 TAHAP 4: Laporan & Export (Admin Dashboard) ✅
Tujuan: Membuat halaman untuk pemilik/admin melihat performa toko dan export data.

Dashboard Admin

Tampilkan card: Omzet Hari Ini, Total Transaksi, Produk Terlaris.

Grafik penjualan (opsional, bisa pakai Chart.js atau sekadar HTML bar chart).

Laporan Penjualan

Buat halaman laporan dengan filter tanggal, kasir, atau metode bayar.

Tampilkan detail item yang terjual.

Export CSV / Excel

Sesuai SKILL.md (generate-print-export), buat route /reports/export menggunakan streaming CSV (BOM UTF-8) agar bisa dibuka di Excel.

Laporan Stok (Low Stock Alert)

Halaman yang menampilkan daftar produk dengan stok di bawah batas minimum.

🚀 TAHAP 5: Testing, Polish & Deployment ✅ (deployment opsional)
Tujuan: Memastikan aplikasi stabil, UI/UX nyaman di tablet, dan siap di-upload ke server.

Testing (PHPUnit)

Tulis Feature Test untuk:

Alur Checkout (pastikan stok berkurang dan total benar).

Alur Void (pastikan stok kembali).

Otorisasi (Kasir tidak bisa akses halaman Laporan/Admin).

Jalankan: php artisan test (pastikan 100% hijau).

Optimasi UI/UX Tablet

Uji coba buka di browser tablet (atau mode Responsive di Chrome DevTools).

Pastikan tidak ada tombol yang ukurannya terlalu kecil (min-h-[44px]).

Pastikan tidak ada scroll horizontal yang mengganggu.

Build Assets

Jalankan npm run build untuk meng-compile Tailwind CSS ke mode produksi.

Deployment (VPS / Shared Hosting Node.js)

Upload ke VPS (Nginx + PHP-FPM + MySQL).

Atur virtual host, jalankan php artisan migrate --seed --force.

Setup cron job untuk queue (jika ada pengiriman email struk).

Arahkan domain/subdomain ke folder public.

🛡️ TAHAP 6: Minimum Enterprise Guard ✅ (Selesai — Commit 1–5)
Tujuan: Menambahkan guard rail 5 lapis ala sistem enterprise (POS/pembayaran/audit) dengan effort realistis. Disusun dari hasil audit codebase terhadap 15 guard.

Hasil Eksekusi

| Commit | Scope | Test baru |
|--------|-------|-----------|
| 1 | Input & Access guard: `#[Locked]`, validasi backend, rate limit login, 10 FormRequest, `ProductService` | GuardInputTest (7), LoginRateLimitTest (4) |
| 2 | Idempotency & concurrency: `client_token`, invoice counter lock, void/shift lock | GuardConcurrencyTest (8) |
| 3 | CHECK constraint driver-aware (9 constraint, aktif di MySQL) | GuardDatabaseConstraintTest (3) |
| 4 | Audit log cakupan penuh + `/audit-logs` + `audit.view` | AuditLogTest (13) |
| 5 | `OPERATIONS.md` — backup, monitoring, backlog (optimistic locking) | — |

Total: 71 test hijau (2 skip — hanya jalan di MySQL), pint bersih. Detail guard per lapisan: `OPERATIONS.md` §5.

Hasil Audit (kondisi sebelum Tahap 6)

Lapisan yang sudah kuat:

Consistency Guard — checkout/void/shift satu DB::transaction.
Integrity Guard — FOREIGN KEY lengkap, snapshot nama+harga item.
Soft Delete Guard — semua tabel bisnis (ADR-0026).
Authorization Guard — Spatie permission + policy + middleware per-route.
Race Condition (parsial) — lockForUpdate pada baris product_variants.

Celah yang ditambal:

Race condition invoice — generateInvoiceNo() baca MAX tanpa lock → tabrakan saat 2 kasir bersamaan.
Idempotency — klik "BAYAR" 2× cepat bisa menghasilkan 2 transaksi.
Void dobel — cek isVoided() di luar transaksi → stok bisa kembali 2×.
Buka shift dobel — check-then-act tanpa lock.
Input tidak dipercaya — $cart Livewire tanpa #[Locked] (qty negatif bisa menambah stok; discount ekstrem bisa membuat total 0); service tidak memvalidasi ulang qty/discount/metode.
Tanpa CHECK constraint — angka negatif bisa lolos jika ada bug aplikasi.
Tanpa rate limiting login — brute force bebas.
Tanpa audit log — adjust stok dari halaman produk tidak mencatat siapa/old/new (celah fraud internal).

Commit 1 — feat(security): input & access guard (Layer 1–2)

#[Locked] pada $cart di PosTerminal (mutasi hanya dari aksi server).
Validasi backend di TransactionService: qty integer ≥ 1, discount di-clamp maks subtotal, paymentMethod in:cash,qris, amountTendered ≥ 0.
Rate limiting login: 5 percobaan/menit per email+IP → 429 (AuthController).
Refactor validasi inline $request->validate() → FormRequest (StoreProductRequest, UpdateProductRequest, StoreCategoryRequest, UpdateCategoryRequest, StoreCustomerRequest, UpdateCustomerRequest, OpenShiftRequest, CloseShiftRequest, VoidTransactionRequest) — kepatuhan AGENTS.md §3.
Test: GuardInputTest, LoginRateLimitTest.

Commit 2 — feat(security): business guard — idempotency, invoice atomik, void idempotent (Layer 3)

Migrasi: kolom client_token (UNIQUE) di transactions; tabel invoice_counters (day PK, last_number).
Token dibuat saat panel bayar dibuka; klik bayar ganda → insert kedua kena unique violation → service kembalikan transaksi lama (idempotent).
generateInvoiceNo() → lockForUpdate pada invoice_counters (sekuens atomik).
voidTransaction() → cek isVoided() di dalam DB::transaction + lockForUpdate baris transaksi.
openShift() → lockForUpdate baris users sebelum cek hasOpenShift.
Test: GuardBusinessTest (6 test: token dobel, sekuens invoice, void dobel, shift dobel, stok 1 → 1 sukses, struk idempotent).

Commit 3 — feat(security): database constraint guard (Layer 4)

Migrasi CHECK constraints (driver-aware, hanya MySQL — SQLite tidak mendukung ALTER ADD CONSTRAINT):
product_variants: stock ≥ 0, price ≥ 0; transaction_items: qty > 0; transactions: subtotal/tax/discount/total ≥ 0; transaction_payments: amount ≥ 0; shifts: start_cash ≥ 0.
UNIQUE invoice_no & sku sudah ada dari awal — jaring pengaman terakhir tetap di DB.

Commit 4 — feat(security): audit guard (Layer 5)

Tabel audit_logs: user_id (nullable FK), action (index), auditable morph, old_values JSON, new_values JSON, ip_address, user_agent, created_at. Tanpa soft-delete — log tidak boleh dihapus.
Service AuditLogger::log(user, action, model, old, new) — null-safe untuk konteks non-HTTP.
Cakupan penuh: stock.adjust, transaction.void, shift.open/close, product.create/update/delete, category.*, customer.*.
Permission audit.view (admin) + halaman /audit-logs (tabel + filter tanggal/aksi).
Test: AuditLogTest.

Commit 5 — docs: rekomendasi operational guard

Backup: cron mysqldump --single-transaction harian, retensi 30 hari, test restore berkala ("bisa recovery ke 14:02?").
Monitoring: LOG_CHANNEL=daily, pail di VPS, alert error 5xx.
Availability: opsi queue struk email, cache rate limiter Redis saat multi-server.
Backlog (belum diimplementasi): optimistic locking produk (kolom version) untuk guard lost update — butuh UX "data sudah diubah orang lain".

Aturan desain yang dikunci

Audit log: cakupan penuh (semua aksi CRUD + stok + void + shift).
Validasi: refactor ke FormRequest (kepatuhan AGENTS.md §3) untuk endpoint produk/kategori/pelanggan/shift/void.
Diskon: backend membatasi maks = subtotal (total minimal 0, pajak tetap dihitung).

🧭 TAHAP 7: Navigasi & Tutup Shift ✅
Tujuan: menutup celah UX yang membuat halaman POS menjadi jalan buntu — kasir tidak bisa keluar dari /pos maupun menutup shift tanpa mengetik URL manual.

Masalah (hasil audit saat Tahap 6)

Tombol "Tutup Shift" hanya ada di satu tempat: dashboard.blade.php bagian "Aksi cepat shift", dan hanya muncul bila shift terbuka.

layouts/app.blade.php (sidebar) tidak punya menu shift sama sekali.

layouts/pos.blade.php hanya berisi {{ $slot }} — tanpa header, tanpa sidebar, tanpa link keluar.

pos-terminal.blade.php: seluruh tombol adalah wire:click (aksi internal) — nol link <a href>.

Akibat: sekali kasir masuk /pos, tidak ada jalan ke Dashboard, tidak ada Logout, tidak ada Tutup Shift. Harus ketik URL manual.

Rencana perbaikan (dua opsi dijalankan bersama)

1. Bar aksi di POS (layouts/pos.blade.php)

Header tipis di atas area split-screen: tombol ← Dashboard dan 🔒 Tutup Shift (tampil hanya bila shift terbuka), plus indikator status shift.

Tutup shift memakai swal:confirm (AGENTS.md §5) sebelum redirect ke /shifts/close — cegah kasir salah menutup shift di tengah transaksi.

Tap target minimal 44px (AGENTS.md §4).

2. Menu Shift di sidebar (layouts/app.blade.php)

Item "Shift" dengan indikator status (terbuka/tertutup), link ke /shifts/close bila terbuka dan /shifts/open bila tertutup.

Dibungkus @can('shifts.view') agar konsisten dengan menu lain.

Batasan: tutup shift tetap lewat form /shifts/close (input kas akhir) — tidak dipanggil langsung dari POS, karena selisih kas wajib dihitung backend.

Test

ShiftNavigationTest: admin/kasir lihat menu shift di sidebar sesuai status; POS menampilkan bar aksi; kasir tanpa shifts.view tidak melihat menu; link tutup shift tidak muncul bila shift tertutup.

Verifikasi akhir: php artisan test, pint, npm run build.

## 📒 TAHAP 8: Laporan Selisih Kas ✅
Tujuan: selisih kas sebelumnya hanya muncul **sekali** sebagai flash message saat tutup shift, lalu hilang — pemilik tidak bisa mengetahui berapa kasir kurang/lebih tanpa query manual ke JSON `audit_logs`.

Masalah

`ShiftService::closeShift()` menghitung `difference`, tapi hanya melemparnya ke flash message (`ShiftController::close()`). Tabel `shifts` tidak punya kolom `expected_cash` maupun `difference` — hanya `start_cash`, `end_cash`, `total_sales`. Laporan yang ada cuma Penjualan dan Stok Menipis; `resources/views/shifts/` isinya form buka/tutup tanpa riwayat.

Perbaikan

| Commit | Scope | Test baru |
|--------|-------|-----------|
| 6 | Kolom rekonsiliasi + `/shifts` riwayat & selisih kas | ShiftHistoryTest (7) |

- Migrasi `090000`: kolom `expected_cash` (unsigned nullable) dan `difference` — **signed**, karena kas kurang itu sah negatif. Backfill menghitung ulang shift lama dengan rumus identik `ShiftService::closeShift()`. `total_sales` tidak bisa dipakai karena mencakup QRIS.
- `ShiftService::closeShift()` kini menyimpan `expected_cash` & `difference` ke baris shift, bukan hanya mengembalikannya.
- `/shifts` (`ShiftController::index`) — filter tanggal/kasir/status, ringkasan (kas awal, kas seharusnya, kas akhir, selisih bersih, jumlah shift bermasalah), kartu per shift dengan badge **Kas sesuai / Kurang / Lebih**.
- Ringkasan dihitung dari kueri yang di-`clone` **sebelum** `paginate()` — kalau tidak, angkanya mentok di 20 baris halaman pertama.

Keputusan otorisasi

Riwayat memakai **`reports.view`**, bukan `shifts.view`. Kasir memang punya `shifts.view` untuk buka/tutup shift miliknya sendiri, tetapi halaman ini memuat selisih kas **seluruh** kasir — itu wewenang pengawas, bukan operator kasir. Menu "Riwayat Shift" di sidebar dibungkus `@can('reports.view')`.

Verifikasi akhir: 87 test hijau (2 skip MySQL-only), pint bersih 103 file, `npm run build` sukses. Backfill teruji langsung di MySQL.

## 🏪 TAHAP 9: Store Config — fondasi 📋 Rencana
Tujuan: nama toko, PPN, dan format mata uang saat ini **di-hardcode di dalam kode dan view**. Mengubah PPN berarti menyunting file PHP dan build ulang ulang. Tahap ini membangun lapisan pengaturan yang bisa diubah admin lewat UI, sekaligus menjadi fondasi untuk tahap berikutnya.

Referensi: modul Store Config pada sistem POS lain (tab Information, General, Localisation).

### Masalah (hasil audit kode)

| Hardcode | Lokasi | Dampak |
|----------|--------|--------|
| `LA PET GALLERY` | `receipt.blade.php:4` | Nama toko mati di struk — padahal baris di atasnya sudah pakai `config('app.name')` |
| `0.11` (PPN 11%) | `TransactionService.php:112` **dan** `PosTerminal.php:90` | Tarif pajak tidak bisa diubah tanpa edit kode di dua tempat |
| `Pajak (11%)` | `receipt.blade.php` | Label persen ikut hardcode |
| `number_format($x, 0, ',', '.')` | tersebar di seluruh view | Format rupiah tidak bisa dikonfigurasi |

Fondasi yang hilang: **tabel `settings` tidak ada sama sekali**. Tanpa itu, form pengaturan hanya jadi tempat menyimpan angka yang tidak dibaca siapa pun.

### Keputusan desain

**Registry `config/settings.php`** — meniru pola `config/modules.php` yang sudah dipakai repo ini untuk permission, bukan skema asing:

```php
'company.name' => [
    'default' => 'LA PET GALLERY',
    'group'   => 'information',
    'label'   => 'Nama Toko',
    'rules'   => ['required', 'string', 'max:120'],
],
```

Tabel key-value (`key` unique, `value` text nullable). Alasannya: store config cenderung bertambah, dan key-value memungkinkan menambah pengaturan **tanpa migrasi**. Validasi tipe dipegang registry, bukan database — supaya `tax.rate` tidak bisa menerima string sembarangan.

`SettingService` memuat **seluruh settings sekali ke memori**, lalu `get()` gratis. Tanpa ini setiap panggilan di dalam loop Blade memicu query.

**Tarif PPN disimpan per transaksi** (`transactions.tax_rate`, backfill `11`). Tanpa kolom ini struk lama akan berbohong: setelah PPN berubah ke 12%, cetak ulang struk November akan menulis "Pajak (12%)" padahal November dikenakan 11%. Bug konsolidasi yang sunyi dan baru terlihat berbulan-bulan kemudian.

### Syarat mutlak

Nilai default harus mereproduksi output hari ini **secara identik**: `decimals=0`, `thousands='.'`, `decimal=','`, simbol `Rp` di kiri. Kalau satu angka berubah, seluruh laporan dan struk mendadak berbeda bentuk.

`tax.included` adalah perubahan perilaku, bukan sekadar label — bila aktif, PPN dikeluarkan dari subtotal (`tax = subtotal − subtotal/(1+rate)`), bukan ditambahkan.

### Rincian per tab

| Tab | Isi | Menyentuh |
|-----|-----|-----------|
| **Information** | Nama toko, alamat, telepon, email, website, logo | `receipt.blade.php` — menghapus hardcode `LA PET GALLERY` |
| **General** | Tarif PPN, PPN termasuk harga, diskon penjualan default | `TransactionService`, `PosTerminal`, struk |
| **Localisation** | Simbol mata uang, posisi, desimal, pemisah ribuan | Seluruh view berformat `number_format` |

### Jebakan: aplikasi ini belum pernah meng-upload gambar

Tidak ditemukan `Storage::`, `hasFile()`, maupun `storeAs()` di seluruh `app/`. Kolom `products.image` ada tapi **tidak pernah ditulis oleh kode aplikasi mana pun** — hanya oleh factory. Dan `public/storage` **belum ada symlink-nya**.

Konsekuensinya jalur logo dibangun dari nol, termasuk `php artisan storage:link` yang **wajib dijalankan di VPS** dan masuk ke checklist rilis `OPERATIONS.md` — kalau tidak, logonya hanya 404 di produksi.

### Urutan commit

| # | Commit | Test baru |
|---|--------|-----------|
| 1 | `feat(settings): tabel settings + registry + SettingService` | SettingServiceTest |
| 2 | `feat(settings): helper format uang (hapus hardcode number_format)` | MoneyFormatTest |
| 3 | `feat(settings): PPN & diskon default configurable + tarif per transaksi` | TaxConfigTest |
| 4 | `feat(settings): halaman /settings — Information, General, Localisation` | SettingsPageTest |
| 5 | `docs: plan.md tahap 9` | — |

### Test yang akan ditulis

- Default mereproduksi `Rp 1.234.567` persis — kalau gagal, seluruh laporan berubah bentuk.
- Ganti simbol/posisi/desimal → output berubah sesuai.
- Tarif PPN berubah → preview POS **dan** transaksi tersimpan memakai tarif baru.
- Backend otoritatif: preview kasir ≠ nilai backend → backend menang (AGENTS.md §3).
- Struk lama memakai `tax_rate` tersimpan, bukan tarif yang sedang berlaku.
- Kasir mendapat 403 di `/settings`.
- Upload logo + file lama terhapus.

### Sengaja ditunda (bukan terlupa)

- **Tax 2 Rate** — tabel `transactions` hanya punya satu kolom `tax`. Mendukung dua tarif berarti menggabungkannya (jadi angka yang tak bisa dibedakan di laporan) atau menambah tabel baru.
- **Tab Barcode** — butuh kolom `barcode` di produk (sekarang hanya `sku`) plus library generate dan halaman cetak label. Modul terpisah.
- **Tab Stock Location** — butuh konsep gudang; restrukturisasi ini mengubah cara guard `lockForUpdate` Tahap 6 bekerja.
- **Tombol Backup Database** — berarti web mengeksekusi `mysqldump` lewat shell. Untuk aplikasi yang bisa diakses jaringan ini membuka jalur eksekusi perintah. Backup sudah benar lewat cron (OPERATIONS.md §1).
- **Pemilihan Printer (Ticket/Invoice/Takings)** — memilih printer fisik di PC kasir via WebUSB. Bukan data yang bermakna disimpan di server, dan tiap perangkat kasir bisa berbeda.
- **Custom Field 1–10** — kolom yang hampir pasti dibiarkan kosong selamanya.

## 👥 TAHAP 10: Modul Karyawan 📋 Rencana
Tujuan: modul `users` **terdaftar di `config/modules.php`** (view/create/update/delete) tapi **tidak pernah diimplementasikan** — registry itu hanya sumber pembuat permission. Tidak ada `UserController`, tidak ada `resources/views/users/`, tidak ada route `/users`. Satu-satunya cara menambah kasir saat ini: menyunting `DatabaseSeeder`.

Keputusan: CRUD lengkap + profil/data karyawan (telepon, alamat, tanggal masuk) + status aktif/nonaktif diatur admin; role baru `manajer`; daftar karyawan hanya untuk admin.

### Empat jebakan

| Jebakan | Solusi |
|---------|--------|
| **Role punya dua sumber kebenaran** — kolom `users.role` (string) dan pivot Spatie `model_has_roles` hidup berdampingan | `UserService` menulis keduanya dalam satu `DB::transaction`. Kolom `role` hanya dibaca di 1 tempat (`layouts/app.blade.php:22`), jadi risiko terkendali tapi tetap wajib lewat service |
| **Soft delete menghapus nama dari riwayat** | `withTrashed()` di 4 relasi: `Shift::user()`, `Transaction::user()`, `AuditLog::user()`, `SuspendedTransaction::user()` |
| **Admin terakhir mengunci diri sendiri** | Guard di `UserService`: akun admin aktif terakhir tidak bisa dinonaktifkan, dihapus, maupun role-nya diturunkan |
| **User nonaktif masih bisa login, sesi lama masih hidup** | `Auth::attempt()` diberi syarat `is_active => true`, ditambah middleware `EnsureUserIsActive` (pola `EnsureShiftOpen`) yang memutus sesi tiap request |

### Role `manajer`

Boleh lihat laporan, riwayat shift, dan kelola produk — tapi **tidak** `products.delete`, **tidak** `audit.view`, **tidak** `users.*`.

```php
'manajer' => [
    'label' => 'Manajer',
    'permissions' => [
        'products.view', 'products.create', 'products.update',
        'categories.view', 'categories.create', 'categories.update',
        'customers.view', 'customers.create', 'customers.update',
        'pos.view', 'sales.view',
        'shifts.view', 'shifts.create', 'shifts.update',
        'transactions.view', 'transactions.print',
        'reports.view', 'reports.export',
    ],
],
```

**Catatan operasional:** `RoleSeeder` tidak jalan otomatis saat `php artisan migrate`. Role `manajer` harus dibuat **di dalam migrasi** (`Role::findOrCreate`) supaya ikut `migrate` — bukan mengandalkan langkah manual yang gampang terlupa di VPS.

### Urutan commit

| # | Commit | Test baru |
|---|--------|-----------|
| 1 | `feat(users): kolom karyawan & status aktif` | — |
| 2 | `feat(users): role manajer di registry` | AuthorizationTest tambahan |
| 3 | `feat(users): UserService — sinkronisasi role & guard akun terakhir` | UserServiceTest |
| 4 | `feat(users): blokir login & sesi user nonaktif` | UserActivationTest |
| 5 | `feat(users): CRUD karyawan` | UserCrudTest |
| 6 | `feat(users): profil sendiri (data & password)` | ProfileTest |
| 7 | `docs: plan.md tahap 10` | — |

Dua halaman baru: `/users` (daftar karyawan, kartu + slide-over form persis pola `customers/index.blade.php`) dan `/profile` (ubah data diri & password sendiri — terbuka untuk semua karyawan tanpa `users.*`).