<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingService::class);
    }

    /**
     * Default registry harus keluar apa adanya sebelum ada yang disimpan —
     * inilah yang membuat seluruh laporan & struk tidak berubah bentuk.
     */
    public function test_returns_registry_defaults_before_anything_saved(): void
    {
        $this->assertEquals('LA PET GALLERY', $this->settings->get('company.name'));
        $this->assertEquals(11, $this->settings->get('tax.rate'));
        $this->assertFalse($this->settings->get('tax.included'));
        $this->assertEquals(0, $this->settings->get('sales.default_discount'));

        $this->assertEquals('Rp', $this->settings->get('currency.symbol'));
        $this->assertEquals('left', $this->settings->get('currency.position'));
        $this->assertEquals(0, $this->settings->get('currency.decimals'));
        $this->assertEquals('.', $this->settings->get('currency.thousands_separator'));
        $this->assertEquals(',', $this->settings->get('currency.decimal_separator'));
    }

    public function test_saves_and_reads_back_values_with_correct_types(): void
    {
        $this->settings->set([
            'company.name' => 'Gallery Baru',
            'tax.rate' => '12',
            'tax.included' => '1',
            'currency.decimals' => '2',
        ]);

        $fresh = app(SettingService::class);

        $this->assertEquals('Gallery Baru', $fresh->get('company.name'));
        $this->assertEquals(12, $fresh->get('tax.rate'));
        $this->assertTrue($fresh->get('tax.included'));
        $this->assertEquals(2, $fresh->get('currency.decimals'));
        $this->assertIsInt($fresh->get('tax.rate'));
        $this->assertIsBool($fresh->get('tax.included'));
    }

    /**
     * Key tidak dikenal tidak boleh tersimpan — form tidak bisa menyuntikkan
     * kolom sembarangan ke tabel.
     */
    public function test_ignores_unknown_keys(): void
    {
        $this->settings->set([
            'company.name' => 'Aman',      // valid, harus tersimpan
            'role' => 'admin',             // bukan pengaturan
            'password' => 'x',             // bukan pengaturan
        ]);

        $this->assertEquals(1, Setting::query()->count());
        $this->assertNull(Setting::query()->where('key', 'role')->first());
        $this->assertNull(Setting::query()->where('key', 'password')->first());
        $this->assertEquals('Aman', $this->settings->get('company.name'));
    }

    public function test_rejects_out_of_range_tax_rate(): void
    {
        try {
            $this->settings->set(['tax.rate' => '150']);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tax.rate', $e->errors());
            $this->assertEquals(0, Setting::query()->count());

            return;
        }

        $this->fail('Tarif PPN di atas 100 seharusnya ditolak.');
    }

    /**
     * Pesan error harus menunjuk label yang dimengerti orang, bukan nama
     * internal 'value' yang dipakai validator (lihat SettingService::set).
     */
    public function test_validation_error_uses_human_label(): void
    {
        try {
            $this->settings->set(['company.name' => '']);
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Nama Toko', $e->errors()['company.name'][0]);

            return;
        }

        $this->fail('Nama toko wajib, seharusnya ditolak.');
    }

    /**
     * String kosong disimpan '' dan terbaca kembali '' — BUKAN jatuh ke
     * default. Pemisah ribuan yang sengaja dikosongkan berarti "tanpa
     * pemisah", bukan "belum pernah diatur" (yang akan salah memakai '.').
     */
    public function test_empty_string_is_preserved_not_falling_back_to_default(): void
    {
        $this->settings->set([
            'company.email' => '',
            'currency.thousands_separator' => '',
        ]);

        $this->assertSame('', Setting::query()->where('key', 'currency.thousands_separator')->value('value'));

        $fresh = app(SettingService::class);
        $this->assertSame('', $fresh->get('currency.thousands_separator'));
        $this->assertSame('', $fresh->get('company.email'));
    }

    public function test_reset_restores_registry_default(): void
    {
        $this->settings->set(['tax.rate' => '5']);
        $this->assertEquals(5, app(SettingService::class)->get('tax.rate'));

        $this->settings->reset('tax.rate');

        $this->assertEquals(11, app(SettingService::class)->get('tax.rate'));
        $this->assertNull(Setting::query()->where('key', 'tax.rate')->first());
    }

    /**
     * Resolver harus benar-benar singleton, kalau tidak tiap pemanggilan di
     * loop view mengulang query.
     */
    public function test_service_is_registered_as_singleton(): void
    {
        $this->assertSame(
            app(SettingService::class),
            app(SettingService::class),
        );
    }

    public function test_unknown_key_returns_caller_default(): void
    {
        $this->assertNull($this->settings->get('tidak.ada'));
        $this->assertEquals('fallback', $this->settings->get('tidak.ada', 'fallback'));
    }
}
