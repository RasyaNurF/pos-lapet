<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingService;

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

        $symbol = (string) $settings->get('currency.symbol', 'Rp');
        $position = (string) $settings->get('currency.position', 'left');
        $decimals = (int) $settings->get('currency.decimals', 0);

        // Bisa saja '' (pemisah sengaja dikosongkan) — biarkan apa adanya.
        $thousands = (string) $settings->get('currency.thousands_separator', '.');
        $decimal = (string) $settings->get('currency.decimal_separator', ',');

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
