🗄️ TAHAP 1: Database & Fondasi (Master Data)
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

🛒 TAHAP 2: POS Terminal (Core Feature)
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

🖨️ TAHAP 3: Cetak Struk & Shift Kasir
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

📊 TAHAP 4: Laporan & Export (Admin Dashboard)
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

🚀 TAHAP 5: Testing, Polish & Deployment
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