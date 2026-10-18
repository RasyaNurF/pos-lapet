# POS Aplikasi Penjualan — Tablet Friendly (Livewire + Tailwind)

Aplikasi Point of Sale (POS) modern untuk **LA PET GALLERY**, didesain khusus untuk **Tablet (Tap-Tap)**. Menggunakan **Laravel 11 + Livewire 3 + Alpine.js + Tailwind CSS (DaisyUI) + MySQL 8**.

## Fitur

| Fitur | Status | Keterangan |
|-------|--------|-----------|
| Auth & RBAC | ✅ | Spatie Permission, login khusus Kasir/Admin |
| **POS Terminal (Tablet)** | ✅ | Split-screen, grid produk, keranjang interaktif, *on-screen numpad* |
| Katalog & Varian | ✅ | Dukungan produk fisik & non-fisik, varian (misal: 1kg, 5kg) |
| Transaksi & Pembayaran | ✅ | Split payment (Cash, QRIS, Debit), hitung kembalian otomatis, *Suspend/Resume* (Hold) |
| Manajemen Pelanggan | ✅ | Poin loyalitas (Loyalty Points), riwayat transaksi |
| Manajemen Stok | ✅ | Stok per outlet/gudang, peringatan stok minimum (*low stock alert*) |
| Laporan & Export | ✅ | Laporan penjualan harian, produk terlaris, export CSV/Excel |
| Shift Kasir | ✅ | Buka/Tutup shift, rekap kas awal & akhir |
| Print Struk | ✅ | Dukungan printer thermal (58mm/80mm) via browser print |

## Perintah (Windows PowerShell)

| Aksi | Perintah |
|------|----------|
| Start dev | `composer run dev` |
| Migrate + seed | `php artisan migrate --seed` |
| Fresh migrate | `php artisan migrate:fresh --seed` |
| Test | `php artisan test` |
| Format PHP | `.\vendor\bin\pint` |
| Build CSS | `npm run build` |

> ⚠️ `php artisan test` dan `npm run build` **jangan dijalankan bersamaan**.

## Akun demo

- Admin: `admin@lapet.com` / `password`
- Kasir: `kasir@lapet.com` / `password`

## Dokumentasi

| File | Fungsi |
|------|--------|
| [`AGENTS.md`](AGENTS.md) | Aturan main untuk AI agent (konvensi backend/frontend, RBAC, Tablet UX) |
| [`DESIGN.md`](DESIGN.md) | Arsitektur & keputusan desain (ADR-0001..) |
| `docs/UI_STANDARD.md` | Standar UI: DaisyUI, token warna, wireframe Tablet |

## RBAC — ringkas

- Permission berformat **`<modul>.<aksi>`** — aksi: `view`, `create`, `update`, `delete`, `print`, `export`.
- Sumber daftar: **`config/modules.php`** (registry) → `PermissionSeeder`.
- Backend dicek di **Policy** (`$user->can(...)`); frontend pakai `@can` / Livewire `$this->authorize()`.

## Testing

```bash
php artisan test
.\vendor\bin\pint
npm run build