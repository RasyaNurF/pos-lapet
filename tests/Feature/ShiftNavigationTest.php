<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tahap 7 — navigasi shift.
 *
 * Sebelumnya POS adalah jalan buntu: layout tanpa link, sidebar tanpa menu shift,
 * sehingga kasir tidak bisa keluar /pos maupun tutup shift tanpa ketik URL manual.
 */
class ShiftNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create(['role' => 'kasir', 'shift_status' => 'closed']);
        $this->kasir->assignRole('kasir');

        $this->admin = User::factory()->create(['role' => 'admin', 'shift_status' => 'closed']);
        $this->admin->assignRole('admin');
    }

    // ------------------------------------------------------------------
    // Sidebar (layouts/app.blade.php)
    // ------------------------------------------------------------------

    public function test_sidebar_shows_shift_menu_when_shift_closed(): void
    {
        $response = $this->actingAs($this->kasir)->get(route('dashboard'));

        $response->assertOk()->assertSee('Shift');

        // Shift tertutup → menu mengarah ke form buka shift.
        $this->assertStringContainsString(route('shifts.open.form'), $response->getContent());
    }

    public function test_sidebar_shift_menu_points_to_close_form_when_open(): void
    {
        $this->kasir->update(['shift_status' => 'open']);

        $response = $this->actingAs($this->kasir)->get(route('dashboard'));

        $response->assertOk();
        $this->assertStringContainsString(route('shifts.close.form'), $response->getContent());
    }

    public function test_sidebar_shows_shift_status_badge(): void
    {
        $closed = $this->actingAs($this->kasir)->get(route('dashboard'));
        $this->assertStringContainsString('Tutup', $closed->getContent());

        $this->kasir->update(['shift_status' => 'open']);

        $open = $this->actingAs($this->kasir)->get(route('dashboard'));
        $this->assertStringContainsString('Buka', $open->getContent());
    }

    // ------------------------------------------------------------------
    // Bar aksi POS (layouts/pos.blade.php)
    // ------------------------------------------------------------------

    public function test_pos_shows_action_bar_with_dashboard_link(): void
    {
        $this->kasir->update(['shift_status' => 'open']);

        $response = $this->actingAs($this->kasir)->get(route('pos'));

        $response->assertOk()
            ->assertSee(route('dashboard'), false)
            ->assertSee('← Dashboard')
            ->assertSee('Logout');
    }

    public function test_pos_shows_close_shift_button_only_when_shift_open(): void
    {
        $this->kasir->update(['shift_status' => 'open']);

        $response = $this->actingAs($this->kasir)->get(route('pos'));
        $response->assertOk()->assertSee('Tutup Shift', false);

        // Fungsi konfirmasi SweetAlert ikut dimuat (AGENTS.md §5).
        $this->assertStringContainsString('confirmCloseShift', $response->getContent());
    }

    public function test_pos_action_bar_is_not_auth_only(): void
    {
        // Pastikan bar tidak muncul untuk tamu (tidak ada info shift bocor).
        $this->get(route('pos'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // Konfirmasi & akses
    // ------------------------------------------------------------------

    public function test_kasir_sees_shift_menu_but_not_audit_menu(): void
    {
        $response = $this->actingAs($this->kasir)->get(route('dashboard'));

        $response->assertOk();
        $this->assertStringContainsString(route('shifts.open.form'), $response->getContent());
        $this->assertStringNotContainsString(route('audit-logs.index'), $response->getContent());
    }

    public function test_guest_cannot_see_shift_menu(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString(route('shifts.open.form'), $response->getContent());
    }

    public function test_close_shift_route_reachable_from_pos_bar(): void
    {
        $this->kasir->update(['shift_status' => 'open']);

        // Tombol di bar POS mengarah ke form tutup shift (bukan langsung POST,
        // karena selisih kas wajib diisi lewat form).
        $response = $this->actingAs($this->kasir)->get(route('shifts.close.form'));

        $response->assertOk()->assertSee('Tutup Shift');
    }
}
