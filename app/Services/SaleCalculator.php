<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Kalkulasi tingkat penjualan (PPN & diskon default) dari pengaturan toko.
 *
 * Kenapa terpisah dari TransactionService: **preview di POS dan tagihan di
 * backend wajib memakai rumus yang sama**. Kalau keduanya menulis ulang rumus
 * sendiri-sendiri, suatu saat pasti akan berbeda — dan itu langsung jadi
 * kerugian uang nyata. Karena itu hanya ada SATU sumber rumus, dipakai
 * PosTerminal (tampil) dan TransactionService (potong).
 */
final class SaleCalculator
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * Tarif PPN dalam persen, mis. 11.0.
     */
    public function taxRate(): float
    {
        return max(0.0, (float) $this->settings->get('tax_rate', 0));
    }

    /**
     * PPN sudah termasuk di harga (dikeluarkan) — bukan ditambahkan.
     */
    public function taxIsIncluded(): bool
    {
        return (bool) $this->settings->get('tax_included', false);
    }

    /**
     * Nilai PPN untuk subtotal tertentu.
     *
     * Cabang eksklusif sengaja mempertahankan rumus lama persis —
     * `(int) round($subtotal * $rate / 100)` — supaya keluaran tidak berubah
     * bentuk walau kode dipindah ke sini.
     */
    public function taxFor(int $subtotal): int
    {
        $rate = $this->taxRate();

        return $this->taxIsIncluded()
            ? (int) round($subtotal * $rate / (100 + $rate))
            : (int) round($subtotal * $rate / 100);
    }

    /**
     * Total belanja.
     *
     * PPN termasuk harga berarti subtotal SUDAH berisi PPN, jadi PPN tidak
     * boleh ditambahkan lagi — hanya diskon yang menguranginya.
     */
    public function totalFor(int $subtotal, int $tax, int $discount): int
    {
        return max(0, $this->taxIsIncluded()
            ? $subtotal - $discount
            : $subtotal + $tax - $discount);
    }

    /**
     * Diskon default toko dalam persen.
     */
    public function defaultDiscountPercent(): float
    {
        return (float) $this->settings->get('sales_default_discount', 0);
    }

    /**
     * Nilai rupiah dari diskon default untuk subtotal tertentu.
     *
     * Tetap dibatasi subtotal supaya diskon tidak pernah melebihi belanja.
     */
    public function defaultDiscountFor(int $subtotal): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        return min($subtotal, (int) round($subtotal * $this->defaultDiscountPercent() / 100));
    }

    /**
     * Format tarif persen untuk tampilan — "11" atau "11.5" — tanpa nol menggantung.
     *
     * Pemisah desimal selalu titik: ini nilai teknis persentase, bukan uang,
     * sehingga TIDAK mengikuti pengaturan currency (mis. "Rp 1.234,50").
     */
    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.');
    }
}
