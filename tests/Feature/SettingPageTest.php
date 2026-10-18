<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->kasir = User::factory()->create(['role' => 'kasir']);
        $this->kasir->assignRole('kasir');
    }

    /**
     * Payload lengkap — semua field wajib harus ikut dikirim, kalau tidak
     * validasi menolak seluruh pengiriman dan tes jadi tidak menguji apa pun.
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'LA PET GALLERY',
            'company_address' => '',
            'company_phone' => '',
            'company_email' => '',
            'company_website' => '',
            'tax_rate' => 11,
            'tax_included' => 0,
            'sales_default_discount' => 0,
            'currency_symbol' => 'Rp',
            'currency_position' => 'left',
            'currency_decimals' => 0,
            'currency_thousands_separator' => '.',
            'currency_decimal_separator' => ',',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Otorisasi
    // ------------------------------------------------------------------

    public function test_admin_can_view_settings_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Pengaturan Toko')
            ->assertSee('Informasi Toko')
            ->assertSee('Umum')
            ->assertSee('Lokalisasi');
    }

    public function test_kasir_cannot_view_settings(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_kasir_cannot_save_settings(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('settings.update'), $this->validPayload(['tax_rate' => 1]))
            ->assertForbidden();

        $this->assertEquals(11, app(SettingService::class)->get('tax_rate'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // Penyimpanan
    // ------------------------------------------------------------------

    public function test_admin_can_save_company_information(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->validPayload([
                'company_name' => 'Toko Baru',
                'company_address' => 'Jl. Mawar 1',
                'company_email' => 'halo@toko.test',
            ]))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('success');

        $settings = app(SettingService::class);

        $this->assertEquals('Toko Baru', $settings->get('company_name'));
        $this->assertEquals('Jl. Mawar 1', $settings->get('company_address'));
        $this->assertEquals('halo@toko.test', $settings->get('company_email'));
    }

    public function test_invalid_tax_rate_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('settings.index'))
            ->put(route('settings.update'), $this->validPayload(['tax_rate' => '500']))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('tax_rate');

        $this->assertEquals(11, app(SettingService::class)->get('tax_rate'));
    }

    /**
     * Uji jebakan checkbox paling mahal: tanpa hidden input "0", browser tidak
     * mengirim apa pun saat checkbox mati, sehingga nilai lama TIDAK PERNAH
     * bisa dimatikan dari UI. Dua langkah ini membuktikan sakelarnya benar-benar
     * bekerja dua arah.
     */
    public function test_tax_included_checkbox_can_be_turned_on_and_off(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->validPayload(['tax_included' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(SettingService::class)->get('tax_included'));

        // Sekarang matikan — inilah yang gagal bila hidden input "0" tidak ada.
        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->validPayload(['tax_included' => 0]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(app(SettingService::class)->get('tax_included'));
    }

    public function test_currency_changes_are_applied(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->validPayload([
                'currency_symbol' => 'IDR',
                'currency_position' => 'right',
                'currency_decimals' => 2,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertEquals('IDR', app(SettingService::class)->get('currency_symbol'));
        $this->assertEquals('1.234.567,00 IDR', Money::format(1234567));
    }

    /**
     * Logo diunggah dari nol — tidak ada kode Storage sama sekali sebelumnya
     * di codebase ini.
     */
    public function test_logo_upload_is_stored_and_linked(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->put(route('settings.update'), $this->validPayload([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]))->assertSessionHasNoErrors();

        $path = app(SettingService::class)->get('company_logo');

        $this->assertNotEmpty($path);
        $this->assertStringStartsWith('settings/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_removing_logo_clears_the_setting(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->put(route('settings.update'), $this->validPayload([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]));

        $path = app(SettingService::class)->get('company_logo');

        $this->actingAs($this->admin)->put(route('settings.update'), $this->validPayload([
            'remove_logo' => 1,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('', app(SettingService::class)->get('company_logo'));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_settings_page_prefills_current_values(): void
    {
        app(SettingService::class)->set(['company_name' => 'Sudah Diubah', 'tax_rate' => 15]);

        $this->actingAs($this->admin)
            ->get(route('settings.index'))
            ->assertSee('Sudah Diubah', false)
            ->assertSee('value="15"', false);
    }
}
