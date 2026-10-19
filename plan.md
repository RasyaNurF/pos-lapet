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
> - [x] TAHAP 9 — Store Config (selesai; 129 test hijau, 2 skip MySQL-only, pint bersih, `npm run build` sukses)
> - [x] TAHAP 10 — Modul Karyawan (selesai; 196 test hijau, 4 skip MySQL-only, pint bersih)
> - [x] TAHAP 11 — Konfirmasi Hapus SweetAlert2 (selesai; 201 test hijau, 4 skip MySQL-only, pint bersih 134 file)
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

## 🏪 TAHAP 9: Store Config — fondasi ✅ Selesai
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
'company_name' => [
    'default' => 'LA PET GALLERY',
    'group'   => 'information',
    'label'   => 'Nama Toko',
    'type'    => 'text',              // dipakai view untuk memilih input
    'rules'   => ['required', 'string', 'max:120'],
],
```

> **Key wajib garis bawah, bukan titik.** Rancangan awal memakai `company.name`, lalu ditemukan saat eksekusi: validator Laravel menafsirkan titik sebagai akses array bersarang, sehingga rule `company.name` mencari `$data['company']['name']` dan tidak akan pernah menemukan input datar `company.name`. Akibatnya **semua rule `required` gagal diam-diam** padahal datanya sudah benar. Dibuktikan langsung:
>
> ```php
> Validator::make(['company.name' => 'x'], ['company.name' => 'required'])->fails();        // true
> Validator::make(['company.name' => 'x'], ['company.name' => 'required'])->getValue('company.name');  // null
> ```
>
> Jebakan yang sama juga menjegal `safe()->only()` (memakai `data_get`). Karena itu titik hanya dipakai untuk permission (`products.view`) yang tidak pernah melewati validator.

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

### Urutan commit (sesuai dieksekusi)

| # | Hash | Commit | Test baru |
|---|------|--------|-----------|
| 1 | `61d988b` | `feat(settings): tabel settings + registry + SettingService` | SettingServiceTest (9) |
| 2 | `4b52145` | `feat(settings): helper format uang, hapus hardcode number_format (38 tempat)` | MoneyFormatTest (7) |
| 3 | `913aec7` | `feat(settings): PPN & diskon default configurable, tarif per transaksi` | ConfigurableTaxTest (15) |
| 4 | `3f6f957` | `feat(settings): halaman /settings — Informasi, Umum, Lokalisasi` | SettingPageTest (11) |
| 5 | — | `docs: plan.md tahap 9` | — |

### Jebakan yang benar-benar ditemukan saat eksekusi

Empat di antaranya tidak terlihat dari perencanaan — semuanya **gagal diam-diam**, bukan melempar error.

**1. Accessor Eloquent mati total karena return type & nama metode**

`Transaction::tax_rate_label` harus tampil di struk. Ditulis `protected function taxRateLabelAttribute(): string`, hasilnya `null` — tanpa error. Dua syarat tersembunyi:

- Nama metode harus **`taxRateLabel()`** (hasil `Str::camel($key)`), **tanpa** akhiran `Attribute`, dan tanpa pola lama `getTaxRateLabelAttribute`.
- Return type harus **`Attribute`**, bukan `string`.

Keduanya diperiksa di `HasAttributes::hasAttributeMutator()` (baris 685 & 691–693). Salah satu saja → accessor dilewati dan `getAttribute()` diam-diam jatuh ke `getRelationValue()` yang mengembalikan `null`.

```php
protected function taxRateLabel(): Attribute
{
    return Attribute::get(fn (): string => SaleCalculator::formatRate((float) $this->tax_rate));
}
```

**2. `tax.rate` bertipe int memotong tarif pecahan**

Tipe nilai ditentukan tipe `default`. `default => 11` (int) membuat `normalize()` menghitung `(string) (int) '11.5'` → `'11'`. Tarif 11,5% hilang senyap. Diperbaiki jadi `11.0`.

**3. Preview POS vs tagihan backend bisa berbeda**

Rumus PPN sebelumnya ditulis dua kali: di `PosTerminal::tax()` (tampilan) dan `TransactionService` (yang dibebankan). Kalau keduanya dikonfigurasi terpisah, lama-lama akan berbeda dan selisihnya langsung jadi kerugian uang nyata. Karena itu dibuat **`SaleCalculator`** — satu-satunya sumber rumus, dipakai kedua sisi. Test `test_pos_preview_matches_backend_*` mengunci kesetaraan ini.

**4. Checkbox tanpa hidden input `0` tidak bisa dimatikan**

`tax_included` adalah boolean. Tanpa `<input type="hidden" name="tax_included" value="0">` sebelum checkbox, saat tidak dicentang browser mengirim apa pun, rule `boolean` melewatinya, dan nilai lama tidak pernah bisa dimatikan dari UI. Diuji dua arah di `test_tax_included_checkbox_can_be_turned_on_and_off`.

**5. Berkas logo jadi yatim**

Cabang "hapus logo" awalnya hanya mengosongkan kolom `company_logo` tanpa menghapus berkasnya — file lama menumpuk di disk tanpa pernah terlihat siapa pun. Diperbaiki lewat `deleteLogo()` yang dipakai baik saat mengganti maupun menghapus.

### Catatan pengujian

Beberapa ekspektasi test ditulis salah pada percobaan pertama — bukan karena kodenya keliru:

- `100 + 11 = 111`, bukan 121. Jadi PPN termasuk harga dari `1.110.000` tepat `110.000`, bukan `10.000` dari `110.000`.
- `100.000 + 11.000 − 10.000 = 101.000`, bukan `99.000`.

Test uang sebaiknya memakai subtotal yang habis dibagi, supaya hasilnya bisa diverifikasi mata tanpa pembulatan.

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

## 👥 TAHAP 10: Modul Karyawan ✅ Selesai
Tujuan awal: modul `users` **terdaftar di `config/modules.php`** (view/create/update/delete) tapi **tidak pernah diimplementasikan** — registry itu hanya sumber pembuat permission. Tidak ada `UserController`, tidak ada `resources/views/users/`, tidak ada route `/users`. Satu-satunya cara menambah kasir saat ini: menyunting `DatabaseSeeder`.

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

### Urutan commit (sesuai dieksekusi)

| # | Hash | Commit | Test baru |
|---|------|--------|-----------|
| 1 | `8cd8bbe` | `feat(users): kolom karyawan & status aktif + guard hapus riwayat` | EmployeeProfileTest (6 + 2 MySQL-only) |
| 2 | `6ceccca` | `feat(users): role manajer di registry + dibuat di migrasi` | ManajerRoleTest (6) |
| 3 | `8777b67` | `feat(users): UserService — sinkronisasi role & guard akun terakhir` | UserServiceTest (12) |
| 4 | `0315e64` | `feat(users): blokir login & sesi user nonaktif` | UserActivationTest (8) |
| 5 | `942d3cc` | `feat(users): CRUD karyawan (admin saja) + slide-over` | UserCrudTest (20) |
| 6 | `bb79892` | `feat(users): profil sendiri (data & password)` | ProfileTest (11) |
| 7 | `3a0aec6` | `feat(users): tolak nonaktifkan karyawan yang shift-nya masih terbuka` | UserServiceTest +4, UserCrudTest +1 |
| 8 | — | `docs: plan.md tahap 10` | — |

**68 test baru**, suite bergerak dari 129 → 196 hijau.

Dua halaman baru: `/users` (daftar karyawan, kartu + slide-over form persis pola `customers/index.blade.php`) dan `/profile` (ubah data diri & password sendiri — terbuka untuk semua karyawan tanpa `users.*`).

### Jebakan yang benar-benar ditemukan saat eksekusi

**1. `cascadeOnDelete` di tiga tabel menunjuk `users`**

`shifts`, `transactions`, dan `suspended_transactions` semuanya dibuat dengan `cascadeOnDelete` — waktu itu users memang belum punya soft delete, jadi rasanya aman. Begitu kolom `deleted_at` ditambahkan, ketiganya berubah jadi ranjau: satu `forceDelete()` akan **menghapus seluruh riwayat keuangan toko**.

Diperbaiki lewat migrasi terpisah yang mengganti `CASCADE` jadi `RESTRICT`, driver-aware (no-op di SQLite, aktif di MySQL) mengikuti pola `add_check_constraints_to_tables`. `audit_logs.user_id` sengaja dibiarkan `SET NULL` — jejak audit memang harus bertahan walaupun pelakunya hilang. Terverifikasi di MySQL sungguhan lewat `php artisan tinker`.

**2. Default kolom tidak pernah sampai ke model hasil factory**

`Eloquent` hanya mengembalikan atribut yang **ia kirim saat INSERT**; nilai yang diisi database tidak pernah masuk ke model. Karena itu `UserFactory` yang tidak menyetel `is_active` menghasilkan model dengan `is_active === null` — dan cast boolean tidak pernah sempat bekerja, sampai model dibaca ulang.

Kebetulan ini berbahaya dua kali:

- `is_active` → `EnsureUserIsActive` membaca `! $user->is_active`, sehingga user yang *sebetulnya aktif* bisa ditendang dari sesinya.
- `shift_status` → guard `hasUnclosedShift()` membandingkan `=== 'open'`; `null === 'open'` diam-diam `false`, jadi guard bisa **gagal terbuka** untuk user yang tidak sengaja kehilangan atributnya.

Keduanya kini ditulis eksplisit di factory, dengan komentar yang menjelaskan sebabnya supaya tidak dikembalikan ke "biar default database saja".

**3. Nama karyawan disuntikkan ke dalam string JS**

Tombol hapus awalnya ditulis begitu saja:

```blade
onclick="swalConfirm({ title: 'Hapus karyawan {{ $employee->name }}?' })"
```

`{{ }}` memang meng-escape HTML — tapi browser **menghapus escape-nya sebelum JavaScript diparse**. Nama `O'Brien` menghasilkan sintaks JS yang rusak dan tombol hapus mati diam-diam, tanpa error di mana pun.

Dipindah ke atribut `data-*` lalu dibaca lewat `this.dataset.*`: nama selalu jadi *nilai* yang dilewatkan ke fungsi, tidak pernah menjadi bagian dari kode sumber.

**4. Shift yatim — regresi yang diperkenalkan oleh Tahap 10 sendiri**

Sebelum `EnsureUserIsActive`, kasir yang dinonaktifkan masih bisa login dan menutup shift-nya. Sesudah middleware itu, tidak lagi — dan `ShiftController::close` memakai `$request->user()`, sehingga **tidak ada satu pun jalur admin untuk menutup shift orang lain**.

Akibatnya, menonaktifkan kasir yang shift-nya masih terbuka mengubur shift itu: `end_cash` tidak pernah dihitung, selisih kasnya tak pernah selesai, dan bila karyawan kelak diaktifkan kembali ia langsung membuka POS di atas shift berhari-hari yang lalu.

Guard tambahan menuntut urutan kerja yang memang benar — hitung kas dulu, cabut akses kemudian — bukan melarang selamanya: setelah shift ditutup, penonaktifan berjalan normal.

### Catatan pengujian

- **Nama metode PHPUnit salah ketik.** Ditulis `assertDoesntMatchRegularExpression` (tidak ada); nama aslinya `assertDoesNotMatchRegularExpression`. Diganti `assertStringNotContainsString` — lebih pendek dan tidak bergantung pada versi PHPUnit.
- **Ekspektasi alamat ditulis dari kepala, bukan dari payload.** Test membandingkan `Jl. Melati` padahal `validPayload()` mengirim `Jl. Melati No. 3`. Kodenya benar, asersinya yang salah.
- **2 test MySQL-only baru** (guard `RESTRICT`) otomatis di-skip di SQLite, sama seperti guard CHECK sebelumnya. Verifikasinya dilakukan manual terhadap MySQL sungguhan.

### Sengaja ditunda (bukan terlupa)

- **Force-close shift orang lain oleh admin** — jalur yang ada saat ini: minta karyawan menutup shiftnya sendiri, baru nonaktifkan. Membuka force-close berarti admin menentukan sendiri `end_cash` untuk kas yang tidak dihitung pemiliknya, dan itu keputusan akuntansi yang tidak boleh dibuat diam-diam.
- **Tombol pulihkan akun terhapus** — soft delete aktif (ADR-0026) dan riwayat tetap bernama lewat `withTrashed()`, tapi belum ada tombol *restore*. Kolom `deleted_at` sudah siap bila kelak dibutuhkan.
- **Foto/avatar karyawan** — butuh mekanisme upload kedua (setelah logo Toko di Tahap 9) dan kolom tambahan; manfaatnya kecil untuk toko kecil.

---

## 🔔 TAHAP 11: Konfirmasi Hapus SweetAlert2 ✅ Selesai

**Masalah.** AGENTS.md §5 mewajibkan konfirmasi aksi destruktif lewat SweetAlert2. Tiga halaman masih memakai `confirm()` bawaan browser:

| Halaman | Aksi | Kondisi |
|---------|------|---------|
| `customers/index.blade.php` | hapus pelanggan | `onsubmit="return confirm(...)"` |
| `products/index.blade.php` | hapus produk | `onsubmit="return confirm(...)"` |
| `categories/index.blade.php` | hapus kategori | `onsubmit="return confirm(...)"` |
| `transactions/index.blade.php` | void transaksi | `onsubmit="return confirm(...)"` — form membentang 2 baris (atribut ada di baris terpisah) |

Alasannya bukan sekadar rapi:

- **Aturan repo dilanggar** di 3 tempat. `/users` (Tahap 10) dan POS sudah benar. Aturan yang dilanggar diam-diam akan makin mudah ditiru developer berikutnya.
- **Tampilan berbeda-beda tergantung browser.** Kasir menghapus produk lewat dialog POS, lalu menghapus kategori lewat dialog bawaan — satu aksi, dua tampilan.

### Solusi — memakai helper yang sudah ada

`swalConfirm({ title, text, url })` **sudah terpasang global** di `layouts/app.blade.php` (baris 119–135) dan dipakai `/users`. Helper itu membangun sendiri form POST berisi `@csrf` + `_method=DELETE`.

Untuk halaman void, helper diberi **satu parameter opsional** `method` (default `DELETE`). Perubahan ini bersifat mundur kompatibel — pemanggil lama tidak perlu diubah sama sekali.

```js
function swalConfirm({ title, text, url, method = 'DELETE' }) {
    // ...
    form.innerHTML = '@csrf<input type="hidden" name="_method" value="' + method + '">';
}
```

Perubahan per halaman hanya **satu baris**: judul dipindahkan ke atribut `data-*`, lalu helper dipanggil dari `onsubmit`.

```blade
onsubmit="swalConfirm({ title: this.dataset.title, url: this.action }); return false;" data-title="Hapus produk {{ $product->name }}?"
```

`this.action` membaca URL dari form, sehingga `url` tidak ditulis ulang — satu sumber, tidak mungkin menyalahi `action` form.

### Empat jebakan

| Jebakan | Solusi |
|---------|--------|
| **Nama disuntikkan ke dalam string JavaScript** | `{{ }}` hanya meng-escape **HTML**; browser mengembalikannya lagi saat membaca atribut, *sebelum* JS diparse. Nama berisi kutip tunggal (mis. `Kado 'Milo'`) memutus string JS yang dibungkus kutip tunggal dan tombol hapus mati tanpa error. Dengan `data-title`, nama selalu dibaca sebagai *nilai*, bukan bagian dari kode. Pola ini sudah terpasang di `/users`. |
| **`swalConfirm()` memaksa `_method=DELETE`** | Rute `transactions.void` adalah **POST sungguhan** (tanpa `@method`). Memakai helper apa adanya mengubah request jadi DELETE → **405 Method Not Allowed**. Solusinya: tambah parameter opsional `method` pada helper, lalu panggil dengan `method: 'POST'` khusus di halaman void. **Nilai default wajib `DELETE`** — tanpanya `/users` dan tiga halaman yang baru dikonversi ikut patah, karena semuanya tiba-tiba terkirim sebagai POST dan ditolak rute DELETE. |
| **`return false` wajib ada** | `swalConfirm()` bersifat asinkron — ia membentuk form baru di dalam `.then()` setelah tombol konfirmasi ditekan. Tanpa `return false`, form asli ikut terkirim seketika, lalu muncul Swal dan form kedua terkirim lagi: **transaksi tercatat dua kali**. |
| **Form asli tidak pernah terkirim** | `swalConfirm()` membangun form **baru**. `action` form asli hanya dipakai sebagai sumber URL (`this.action`); `@csrf` dan `@method`-nya tidak pernah terkirim — yang terkirim adalah milik helper. Akibatnya menambah `@method('PUT')` pada form asli tidak mengubah apa pun. Jangan mencari ke sana saat mendebug. |

### Keputusan desain

Form tidak dihapus. Hanya atribut `onsubmit` yang diganti, sehingga `@csrf` dan `@method` tetap utuh sebagai fallback bila JS gagal dimuat — tombol tetap terkirim langsung, persis perilaku aslinya.

### Commit

| Commit | Isi |
|--------|-----|
| `9d8e9b3` | `feat(ui): konfirmasi destruktif seragam pakai SweetAlert2` — 5 file tampilan + `SweetAlertConsistencyTest` (5 test) |

### Koreksi saat eksekusi

Rencana awal mencatat bahwa void **sudah** memakai SweetAlert lewat `addEventListener`, dan bahwa baris `onsubmit`-nya hanya teks mati. Keduanya keliru. Halaman void ternyata murni memakai `confirm()` bawaan — tidak ada `Swal.fire` di mana pun — dan baris `onsubmit` itu atribut sungguhan yang menempel pada tag `<form>` yang membentang dua baris.

Akibatnya berubah total: bukan sekadar membersihkan sisa kode, void benar-benar perlu dikonversi — dan karena routenya POST, konversinya menuntut parameter `method` pada helper. Tanpa koreksi ini, pekerjaan berhenti di tiga halaman dan AGENTS.md tetap dilanggar di keempatnya.

### Test — `SweetAlertConsistencyTest`

1. `test_native_browser_confirm_is_gone_from_every_destructive_page` — keempat halaman, `assertDontSee('onsubmit="return confirm(', false)`
2. `test_every_destructive_page_offers_a_sweetalert_dialog` — keempat halaman `assertSee('swalConfirm(')`
3. `test_void_form_asks_for_post_instead_of_delete` — halaman void memuat `method: 'POST'`; tanpanya ia terkirim sebagai DELETE dan berakhir 405
4. `test_delete_forms_keep_their_csrf_and_method_spoofing` — memastikan konversi tidak merobek form; `name="_token"` dan `value="DELETE"` tetap ter-render
5. `test_a_name_with_quotes_renders_safely_into_data_title` — kategori bernama `Kado 'Milo'` tetap merender tombol, dan nama muncul sebagai `data-title="Hapus kategori Kado &#039;Milo&#039;?"` (nilai ter-escape), bukan di dalam string JS. Kutip tunggal dipilih karena dialah yang memutus string JS ber-single-quote; kutip ganda tidak merusak apa pun.

Test ke-5 adalah satu-satunya yang bisa menangkap jebakan injeksi — empat lainnya lolos bahkan kalau nama disuntikkan ke string JS.

---

## 🚀 DEPLOYMENT

Tahap 1–11 selesai, ter-commit, dan sudah ter-push ke `main`. Catatan untuk deployment berikutnya: remote repo memakai **SSH** (`git@github.com:...`), bukan HTTPS — URL HTTPS-nya yang menjawab 404 dan sempat salah dibaca sebagai "repo tidak ada". Saat rilis, jalankan `php artisan storage:link`; tanpanya logo toko dari Tahap 9 tidak tampil.

### Permission yatim: menu menghilang tanpa error

Gejala: login sebagai admin, tapi menu ⚙️ Pengaturan Toko tidak muncul padahal rute, view, dan `@can('settings.view')` semuanya benar.

Penyebabnya di konstruksi seeder. `PermissionSeeder` hanya **membuat** permission dari `config/modules.php`; `RoleSeeder` mengambil `Permission::all()` sebagai **snapshot**. Saat modul `settings` ditambahkan di Tahap 9, permission `settings.view` dan `settings.update` berhasil tercipta — tetapi `RoleSeeder` tidak dijalankan ulang, sehingga tidak ada role yang memegang keduanya. `@can` menolak, menu disembunyikan, dan tidak ada error di mana pun.

**Perbaikan:** `PermissionSeeder` kini memanggil `RoleSeeder` di akhir `run()`. Membuat permission selalu berarti memberikannya ke role, sehingga kasusnya mustahil terulang. Diverifikasi end-to-end — mencabut `settings.view` dari admin lalu menjalankan `db:seed --class=PermissionSeeder` mengembalikannya otomatis, tanpa mengubah jumlah permission kasir (12) dan manajer (18).

Catatan: `DatabaseSeeder` sudah memanggil kedua seeder, jadi instalasi baru (`migrate --seed`) tidak pernah kena. Bug ini hanya muncul saat modul ditambah belakangan.

Pemeriksaan cepat bila menemukan menu yang hilang — daftar harus kosong, artinya semua permission dipegang setidaknya satu role:

```bash
php artisan tinker --execute="dump(Spatie\Permission\Models\Permission::query()->whereDoesntHave('roles')->pluck('name')->all());"
```