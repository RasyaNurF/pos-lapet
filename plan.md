> **Status pengerjaan**
>
> - [x] TAHAP 1 — Database & Fondasi (selesai)
> - [x] TAHAP 2 — POS Terminal (selesai)
> - [x] TAHAP 3 — Cetak Struk & Shift Kasir (selesai)
> - [x] TAHAP 4 — Laporan & Export (selesai)
> - [x] TAHAP 5 — Testing, Polish & Build Assets (selesai; 37 test hijau, pint bersih, `npm run build` sukses)
> - [x] TAHAP 6 — Minimum Enterprise Guard (selesai; 71 test hijau, 2 skip MySQL-only, pint bersih)
> - [x] TAHAP 7 — Navigasi & Tutup Shift (selesai; POS bukan lagi jalan buntu)
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