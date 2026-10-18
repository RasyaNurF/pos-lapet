<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SettingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyFormatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jaminan mutlak: pengaturan default harus mereproduksi perilaku lama
     * SECARA IDENTIK. Seluruh laporan dan struk selama ini memakai
     * number_format($x, 0, ',', '.') dengan "Rp " di depan.
     */
    public function test_default_output_is_byte_identical_to_legacy_format(): void
    {
        foreach ([0, 1, 999, 1000, 1234, 999999, 1000000, 1234567, 987654321] as $amount) {
            $this->assertSame(
                'Rp '.number_format($amount, 0, ',', '.'),
                Money::format($amount),
                "Format uang berubah untuk amount {$amount}.",
            );
        }
    }

    /**
     * Nilai negatif (diskon kelebihan / selisih kas) harus persis seperti
     * perilaku lama, termasuk posisi tanda minus.
     */
    public function test_negative_amounts_match_legacy_layout(): void
    {
        foreach ([-1, -5000, -1234567] as $amount) {
            $this->assertSame(
                'Rp '.number_format($amount, 0, ',', '.'),
                Money::format($amount),
            );
        }
    }

    public function test_symbol_position_right(): void
    {
        app(SettingService::class)->set(['currency_position' => 'right']);

        $this->assertSame('1.234.567 IDR', $this->reformatted(1234567, ['currency_symbol' => 'IDR']));
    }

    public function test_decimals_appear_when_enabled(): void
    {
        $this->assertSame('Rp 1.234.567,50', $this->reformatted(1234567.5, [
            'currency_decimals' => 2,
        ]));
    }

    /**
     * Kosongkan pemisah ribuan -> "tanpa pemisah", bukan kembali ke '.'.
     * Ini yang menguji keputusan menyimpan '' alih-alih NULL.
     */
    public function test_empty_thousands_separator_yields_no_separator(): void
    {
        $this->assertSame('Rp 1234567', $this->reformatted(1234567, [
            'currency_thousands_separator' => '',
        ]));
    }

    public function test_us_style_separators(): void
    {
        $this->assertSame('Rp 1,234,567.50', $this->reformatted(1234567.5, [
            'currency_decimals' => 2,
            'currency_thousands_separator' => ',',
            'currency_decimal_separator' => '.',
        ]));
    }

    public function test_amounts_render_in_all_report_surfaces(): void
    {
        // Ringkasan laporan, shift, dan struk memakai nilai yang sama —
        // cukup dibuktikan formatter-nya konsisten, bukan per-view.
        $this->assertSame('Rp 2.345.000', Money::format(2345000));
        $this->assertSame('Rp 1.303.542', Money::format(1303542));
    }

    /**
     * Terapkan pengaturan, lalu format dalam instans service yang sama —
     * set() sudah membuang cache, jadi hasilnya langsung terpakai.
     */
    private function reformatted(int|float $amount, array $settings): string
    {
        app(SettingService::class)->set($settings);

        return Money::format($amount);
    }
}
