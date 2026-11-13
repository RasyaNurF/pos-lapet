<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ExchangeRateService;
use App\Services\SettingService;
use RuntimeException;

/**
 * Format jumlah uang mengikuti pengaturan toko.
 *
 * SYARAT MUTLAK: nilai default harus mereproduksi perilaku lama persis —
 *
 *     number_format($x, 0, ',', '.')  lalu "Rp " di depan
 *     => "Rp 1.234.567"
 *
 * Karena seluruh laporan dan struk selama ini memakainya. Kalau satu angka
 * berubah, semua tampilan uang mendadak berbeda bentuk.
 *
 * Dipanggil dari Blade lewat directive @money(...).
 */
class Money
{
    public static function format(int|float $amount): string
    {
        /** @var SettingService $settings */
        $settings = app(SettingService::class);

        $symbol = (string) $settings->get('currency_symbol', 'Rp');
        $position = (string) $settings->get('currency_position', 'left');
        $decimals = (int) $settings->get('currency_decimals', 0);

        // Bisa saja '' (pemisah sengaja dikosongkan) — biarkan apa adanya.
        $thousands = (string) $settings->get('currency_thousands_separator', '.');
        $decimal = (string) $settings->get('currency_decimal_separator', ',');

        $currency = config('currencies')[$symbol]['code'] ?? 'IDR';
        try {
            $amount *= app(ExchangeRateService::class)->quote($currency)['rate'];
        } catch (RuntimeException) {
            // Tanpa kurs, tampilkan nilai dasar dengan label rupiah yang benar.
            $symbol = 'Rp';
            $decimals = 0;
        }

        $number = number_format(
            (float) $amount,
            $decimals,
            $decimal,
            $thousands,
        );

        return $position === 'right'
            ? $number.' '.$symbol
            : $symbol.' '.$number;
    }
}
