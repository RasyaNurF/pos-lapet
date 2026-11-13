# OPERATIONS.md — Operational Guard (Tahap 6, Commit 5)

## Konversi mata uang tampilan

- Pilih simbol di **Pengaturan Toko → Lokalisasi**. Pratinjau langsung mengambil kurs; klik **Simpan Pengaturan** untuk menerapkannya ke aplikasi dan struk/PDF.
- Semua data harga, pajak, diskon, pembayaran, shift, serta ekspor CSV tetap dalam **IDR**. Dropdown mengonversi nominal tampilan, tanpa mengubah harga tersimpan. Input nominal tetap berlabel rupiah.
- Kurs referensi diambil server dari [Frankfurter v2](https://frankfurter.dev/), pasangan IDR ke mata uang pilihan, tanpa API key. Tanggal kurs ditampilkan; nominal tampilan transaksi lama mengikuti kurs tampilan yang berlaku saat dibuka kembali.
- Cache kurs segar berlaku satu jam. Saat layanan gagal, kurs terakhir yang tersimpan maksimal tujuh hari dipakai dengan pemberitahuan. Jika belum ada kurs, perubahan ke mata uang tersebut ditolak; halaman yang sudah memakai mata uang asing sementara menampilkan rupiah dengan pemberitahuan.
- Server memerlukan koneksi HTTPS ke `api.frankfurter.dev`. Verifikasi TLS tetap aktif.

Rekomendasi operasional di luar kode: backup, monitoring, ketersediaan, dan backlog.
Bagian ini **belum diimplementasi** — butuh akses VPS/infrastruktur, bukan perubahan kode.

Untuk guard yang sudah ada di kode, lihat [plan.md § Tahap 6](plan.md) dan ringkasan di bagian 5 dokumen ini.

---

## 1. Backup Database

Database adalah aset paling berharga di POS (transaksi, stok, jejak audit).

### Skema harian

```bash
# /etc/cron.d/pos-backup — pukul 02.00 WIB
0 2 * * * root mysqldump --single-transaction --routines --triggers laravel-pos | gzip > /var/backups/pos/laravel-pos-$(date +\%F).sql.gz
```

- `--single-transaction` → snapshot konsisten **tanpa lock tabel**, aman dijalankan saat shift berjalan.
- Kompresi gzip: struk 1 tahun biasanya < 1 GB tergantung volume.

### Retensi

| Jenis | Retensi | Catatan |
|-------|---------|---------|
| Harian | 30 hari | Hapus otomatis `find /var/backups/pos -name '*.sql.gz' -mtime +30 -delete` |
| Mingguan | 12 minggu | Disimpan terpisah, tahan terhadap backup harian yang rusak |
| Bulanan | 12 bulan | Arsip jangka panjang (untuk audit & pajak) |

### Wajib: uji restore berkala

Backup yang tidak pernah diuji = tidak ada backup.

- Jadwal: **bulanan**, di staging/DB kosong.
- Pertanyaan yang harus terjawap: *"Bisa recovery ke posisi 14.02 kemarin?"*
- Langkah: restore file terbaru → `php artisan migrate --seed` bila perlu → jalankan `php artisan test` → cek jumlah transaksi & selisih kas terakhir.
- Catat durasi RTO (recovery time objective) tiap uji — ini patokan waktu yang dijanjikan ke pengguna.

### Lokasi backup

Jangan hanya di mesin yang sama. Minimal **2 lokasi berbeda** (mis. VPS + object storage / disk eksternal), dan satu di lokasi terpisah secara fisik/geografis bila data dianggap kritis.

---

## 2. Monitoring & Alerting

### Logging

```
# .env (produksi)
LOG_CHANNEL=daily
LOG_LEVEL=warning
```

- File log di `storage/logs/laravel.log` tumbuh tak terbatas → pasang **logrotate** (mis. 14 hari, compress).
- Di VPS: `php artisan pail` untuk stream log real-time saat investigasi (jangan dibiarkan jalan permanen).

### Yang harus di-alert

| Sinyal | Ambang awal | Tindakan |
|--------|-------------|----------|
| Error 5xx | > 5 / 5 menit | Cek log, SK tingkatkan |
| Disk usage | > 85% | Bersihkan log/cache/backup lama |
| MySQL down / restart loop | 1 kejadian | Cek `dmesg`, OOM killer |
| Gagal cron backup | 1 kejadian | Cek disk & kredensial MySQL |
| SSL expiry | < 14 hari | Perpanjang otomatis (certbot) |
| Disk I/O / slow query | berkala | Audit query lambat |

Alur umum: log → Uptime Kuma / Healthchecks.io / Grafana+Loki → notifikasi Telegram/WA/email.

### Kesalahan yang harus terlihat di log aplikasi

`AuditLogger` sudah menulis `Log::error('Gagal menulis audit log', ...)` bila penulisan audit gagal.
**Alert khusus untuk ini** — audit yang gagal berarti jejak keamanan sedang bocor (bisa jadi tabel penuh, koneksi putus, atau ada yang sengaja).

---

## 3. Ketersediaan & Skalabilitas

| Kebutuhan | Solusi | Kapan perlu |
|-----------|--------|-------------|
| Rate limiter lintas proses | `CACHE_DRIVER=redis` (bukan `file`) | Multi-server / multi-worker |
| Struk antri lama | `QUEUE_CONNECTION=database` + worker | Saat cetak struk lambat menghambat kasir |
| Sesi lintas proses | `SESSION_DRIVER=database`/`redis` | > 1 server |
| Batas request | `pail` + rate limit nginx (`limit_req`) | Lindungi dari flood/bot |

Catatan: **satu tablet kasir = satu koneksi**, jadi beban normal rendah. Skalakan horizontal hanya bila memang menambah kasir/server.

---

## 4. Backlog (belum diimplementasi)

### 4.1 Optimistic locking produk (guard "lost update")

**Masalah:** dua admin membuka form produk bersamaan. Yang A menyimpan duluan, lalu yang B menyimpan — perubahan A tertimpa diam-diam (*lost update*).

**Solusi:** tambah kolom versi:

```php
$table->unsignedInteger('version')->default(1);
```

- Form menyertakan `version` yang dibaca saat dibuka.
- Saat simpan: `UPDATE ... WHERE id = ? AND version = ?` → bila 0 baris terpengaruh, berarti ada yang menyinggung lebih dulu.
- Balik ke pengguna dengan pesan: **"Data sudah diubah orang lain. Muat ulang untuk melihat versi terbaru."**

**Status:** sengaja ditunda. Ini *last-write-wins* yang berbahaya, tapi dampaknya terbatas pada data master (bukan uang/stok). Stok & uang sudah dilindungi `lockForUpdate` di `TransactionService`.

### 4.2 Item lain

- **Uji beban konkurensi nyata** — test suite memakai SQLite satu koneksi, sehingga `lockForUpdate` tidak benar-benar diuji paralel. Perlu uji terhadap MySQL dengan dua koneksi sungguhan.
- **Penghapusan log audit** — saat ini append-only tanpa mekanisme purge. Bila volume besar, tambah retensi (mis. 2 tahun) dengan proses tercatat & diaudit, bukan penghapusan diam-diam.
- **Notifikasi stok menipis** — laporan sudah ada, belum ada push/email otomatis.

---

## 5. Ringkasan guard yang sudah ada di kode (Tahap 6)

| Lapisan | Guard | Lokasi |
|---------|-------|--------|
| **1. Input** | `#[Locked]` `$cart` & `$checkout_token`; validasi ulang qty ≥ 1, diskon ≤ subtotal, metode `in:cash\|qris`, uang diterima ≥ 0 | `PosTerminal`, `TransactionService` |
| **1. Input** | Validasi wajib lewat FormRequest (10 request) | `app/Http/Requests` |
| **2. Akses** | Rate limit login 5/menit per email+IP | `AuthController` |
| **2. Akses** | Permission `<modul>.<aksi>` + Policy + `audit.view` | `config/modules.php`, `app/Policies` |
| **3. Bisnis** | Idempotency `client_token` (klik bayar ganda → 1 transaksi) | `TransactionService` |
| **3. Bisnis** | Invoice counter `lockForUpdate` (tanpa nomor dobel) | `TransactionService` |
| **3. Bisnis** | Void & shift ganda ditolak lewat row lock | `TransactionService`, `ShiftService` |
| **4. Data** | CHECK constraint (9) — stok/qty/uang tak negatif di DB | migrasi `080002` |
| **4. Data** | Soft delete seluruh tabel bisnis (ADR-0026) | seluruh model |
| **5. Operasional** | Audit log append-only cakupan penuh + halaman `/audit-logs` | `AuditLogger`, `AuditLogController` |
| **5. Operasional** | Backup, monitoring, alerting | **belum** — dokumen ini |

---

## 6. Checklist rilis produksi

- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` benar
- [ ] `php artisan optimize` (config + route cache)
- [ ] Cron backup harian terpasang **dan pernah diuji restore**
- [ ] Logrotate untuk `storage/logs`
- [ ] SSL terpasang & auto-renew
- [ ] `php artisan test` hijau sebelum deploy
- [ ] Seed awal: `php artisan migrate --seed` (akun admin diganti password defaultnya)
- [ ] `php artisan storage:link` — **tanpa ini logo toko 404 di produksi**. Aplikasi tidak pernah memakai `Storage::` sebelum Tahap 9, jadi symlink-nya tidak pernah dibuat. Cek `public/storage` benar-benar mengarah ke `storage/app/public`.
- [ ] Monitoring uptime aktif, notifikasi masuk ke kanal yang diawasi orang

---

## 7. Jebakan frontend yang sudah diketahui

### 7.1 Tombol SweetAlert2 transparan (sudah diperbaiki)

**Gejala:** dialog muncul, judul & isi terbaca, tapi tombol **Ya/Batal tidak terlihat**
padahal sudah ada di DOM (ruang kosong di bawah teks). Pernah terjadi pada
"Tutup shift sekarang?" (Tahap 7).

**Akar masalah — bukan CSS SweetAlert2 hilang, melainkan perang *specificity*:**

```css
/* Preflight Tailwind (resources/css/app.css → @tailwind base) */
button,[type=button],[type=reset],[type=submit]{background-color:#0000}
/* specificity: attribute selector = 0-1-0  */

/* SweetAlert2 v11 — semua class dibungkus :where(), jadi bernilai 0 */
div:where(.swal2-container) button:where(.swal2-styled):where(.swal2-confirm){
  background-color: var(--swal2-confirm-button-background-color);
}
/* specificity: hanya 2 elemen = 0-0-2  */
```

`0-1-0` mengalahkan `0-0-2` → `background-color` dipaksa transparan, sementara
warna teks tetap `#fff`. Hasilnya **tombol putih di atas popup putih**.

**Perbaikan:** dideklarasikan ulang di `resources/css/app.css` dengan selector
lebih spesifik (`div:where(.swal2-container) button.swal2-styled`, yaitu `0-1-2`).
Warna tetap diambil dari CSS custom property milik SweetAlert2, sehingga
`confirmButtonColor` / `cancelButtonColor` pada `Swal.fire()` tetap berlaku.

**Aturan main untuk jebakan serupa:** setiap library CSS pihak ketiga yang
dibundel lewat `@tailwind base`/`components` berisiko **kalah specificity dari
Preflight**. Bila ada elemen library itu tampak "hilang tapi menempati ruang",
periksa Preflight lebih dulu — jangan langsang curiga CSS gagal dimuat.

### 7.2 Checklist verifikasi visual

- [ ] `npm run build` setelah mengubah `resources/css/app.css`
- [ ] Uji ulang **semua** dialog SweetAlert2 (notifikasi sukses/error POS, konfirmasi
      hapus keranjang, tutup shift) — perbaikan di §7.1 bersifat global, bukan
      khusus dialog tutup shift
