<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jebakan #4 Tahap 10 — user nonaktif masih bisa login, sesi lama masih hidup.
 *
 * Keduanya ditutup terpisah karena menutup satu tidak menutup yang lain:
 * `Auth::attempt()` menutup pintu masuk, `EnsureUserIsActive` mengusir yang
 * sudah berada di dalam.
 */
class UserActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'shift_status' => 'closed',
            'is_active' => true,
        ]);
        $this->kasir->assignRole('kasir');
    }

    // ------------------------------------------------------------------
    // Pintu masuk — Auth::attempt
    // ------------------------------------------------------------------

    public function test_active_user_can_login(): void
    {
        $this->post(route('login'), [
            'email' => $this->kasir->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->kasir);
    }

    public function test_inactive_user_cannot_login_even_with_the_correct_password(): void
    {
        $this->kasir->update(['is_active' => false]);

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => $this->kasir->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Pesan untuk akun nonaktif wajib identik dengan pesan kredensial salah.
     *
     * Bila berbeda, siapa pun bisa mendaftarkan email apa pun dan langsung
     * tahu alamat mana yang terdaftar sekaligus sedang dinonaktifkan.
     */
    public function test_inactive_login_is_indistinguishable_from_a_wrong_password(): void
    {
        $this->kasir->update(['is_active' => false]);

        $this->post(route('login'), [
            'email' => $this->kasir->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => 'Email atau password salah.']);

        // Alamat yang sama sekali tidak terdaftar → pesan persis sama.
        $this->post(route('login'), [
            'email' => 'belum.pernah.ada@lapet.test',
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => 'Email atau password salah.']);
    }

    public function test_deactivated_account_cannot_be_reactivated_through_login_attempt(): void
    {
        $this->kasir->update(['is_active' => false]);

        // Sengaja dicoba berulang — attempt gagal tidak pernah mengubah
        // status akun apa pun.
        foreach (range(1, 3) as $attempt) {
            $this->post(route('login'), [
                'email' => $this->kasir->email,
                'password' => 'password',
            ]);

            $this->assertGuest();
            $this->assertFalse($this->kasir->fresh()?->is_active, 'Percobaan login mengubah status akun.');
        }
    }

    // ------------------------------------------------------------------
    // Sesi lama — EnsureUserIsActive
    // ------------------------------------------------------------------

    public function test_deactivated_user_is_logged_out_on_the_next_request(): void
    {
        $this->actingAs($this->kasir);

        // Admin menonaktifkan akunnya SELAGI sesi masih berjalan.
        $this->kasir->update(['is_active' => false]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest('web', 'Sesi lama masih hidup setelah dinonaktifkan.');
    }

    public function test_deactivated_user_is_blocked_on_every_route_not_just_the_dashboard(): void
    {
        $this->actingAs($this->kasir);

        $this->kasir->update(['is_active' => false]);

        // Bukan satu rute yang dijaga — daftar karyawan pun harus menolak.
        $this->get(route('customers.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_active_user_sessions_are_left_alone(): void
    {
        $this->actingAs($this->kasir);

        $this->get(route('dashboard'))->assertOk();

        $this->assertAuthenticatedAs($this->kasir);
    }

    /**
     * Pengunjung yang belum login tidak boleh tersangkut di middleware ini —
     * kalau tidak, halaman login sendiri yang akan mengalihkan terus-menerus.
     */
    public function test_guest_is_not_logged_out_by_the_middleware(): void
    {
        $this->get(route('login'))
            ->assertOk();

        $this->assertGuest();
    }
}
