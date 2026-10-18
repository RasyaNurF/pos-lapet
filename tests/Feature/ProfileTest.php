<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Profil sendiri (plan.md Tahap 10, commit 6).
 *
 * Halaman ini satu-satunya tempat di aplikasi di mana karyawan menyentuh
 * baris `users`-nya sendiri tanpa `users.*`. Karena itu pertanyaan terpenting
 * di kelas ini bukan "bisa disimpan?", melainkan "apa saja yang TIDAK boleh
 * bisa diubah dari sini?".
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Profil',
            'role' => 'kasir',
            'shift_status' => 'closed',
        ]);
        $this->kasir->assignRole('kasir');
    }

    // ------------------------------------------------------------------
    // Akses
    // ------------------------------------------------------------------

    public function test_kasir_can_open_their_own_profile_without_users_permission(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Profil Saya');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
        $this->put(route('profile.update'), $this->payload($this->kasir))
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // Perubahan data diri
    // ------------------------------------------------------------------

    public function test_kasir_can_update_their_personal_data(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, [
                'name' => 'Nama Sudah Diperbarui',
                'phone' => '08123123123',
                'address' => 'Jl. Baru No. 9',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $this->kasir->fresh();

        $this->assertSame('Nama Sudah Diperbarui', $fresh?->name);
        $this->assertSame('08123123123', $fresh?->phone);
        $this->assertSame('Jl. Baru No. 9', $fresh?->address);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.update']);
    }

    public function test_email_must_be_unique_against_other_employees(): void
    {
        User::factory()->create(['email' => 'punya.orang@lapet.test']);

        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, [
                'email' => 'punya.orang@lapet.test',
            ]))
            ->assertSessionHasErrors(['email' => 'Email ini sudah dipakai karyawan lain.']);
    }

    // ------------------------------------------------------------------
    // Jebakan terbesar: profil sendiri sebagai jalan eskalasi
    // ------------------------------------------------------------------

    /**
     * role, is_active, dan hire_date sengaja tidak divalidasi, sehingga tidak
     * pernah masuk `validated()` dan tidak pernah sampai ke service. Kirim
     * tiga kolom itu apa pun nilainya — semuanya harus diabaikan.
     */
    public function test_profile_cannot_be_used_to_escalate_role_or_status(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, [
                'role' => 'admin',
                'is_active' => 0,
                'hire_date' => '2020-01-01',
            ]))
            ->assertSessionHas('success');

        $fresh = $this->kasir->fresh();

        $this->assertSame('kasir', $fresh?->role, 'Profil sendiri bisa dipakai menaikkan role sendiri.');
        $this->assertTrue($fresh?->is_active, 'Status akun berubah lewat profil sendiri.');
        $this->assertNull($fresh?->hire_date, 'Tanggal masuk berubah lewat profil sendiri.');

        $this->assertTrue($fresh?->hasRole('kasir'));
        $this->assertFalse($fresh?->hasRole('admin'), 'Pivot Spatie ikut ter-promosi walau kolom tidak.');
    }

    /**
     * Sengaja ditulis tanpa middleware `permission:users.update` di route —
     * pengaman terakhir ada di policy, dan policy-lah yang harus menolak.
     */
    public function test_policy_allows_self_service_but_not_someone_else(): void
    {
        $other = User::factory()->create(['role' => 'kasir']);
        $other->assignRole('kasir');

        $this->assertTrue($this->kasir->can('update', $this->kasir), 'Karyawan harus bisa mengubah profilnya sendiri.');
        $this->assertFalse($this->kasir->can('update', $other), 'Karyawan tidak boleh mengelola akun orang lain.');
    }

    // ------------------------------------------------------------------
    // Password
    // ------------------------------------------------------------------

    public function test_changing_password_requires_the_current_one(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, [
                'password' => 'password-baru-1',
                'password_confirmation' => 'password-baru-1',
            ]))
            ->assertSessionHasErrors([
                'current_password' => 'Masukkan password Anda saat ini untuk mengganti password.',
            ]);

        $this->assertTrue(
            Hash::check('password', $this->kasir->fresh()?->password ?? ''),
            'Password berubah walau password lama tidak pernah dimasukkan.',
        );
    }

    public function test_a_wrong_current_password_is_rejected(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, [
                'current_password' => 'salah-sekali',
                'password' => 'password-baru-2',
                'password_confirmation' => 'password-baru-2',
            ]))
            ->assertSessionHasErrors([
                'current_password' => 'Password saat ini salah.',
            ]);

        $this->assertTrue(Hash::check('password', $this->kasir->fresh()?->password ?? ''));
    }

    public function test_password_changes_when_the_current_one_is_correct(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, [
                'current_password' => 'password',
                'password' => 'rahasia-baru-9',
                'password_confirmation' => 'rahasia-baru-9',
            ]))
            ->assertSessionHas('success');

        $fresh = $this->kasir->fresh();

        $this->assertTrue(Hash::check('rahasia-baru-9', $fresh?->password ?? ''), 'Password baru tidak berlaku.');
        $this->assertFalse(Hash::check('password', $fresh?->password ?? ''), 'Password lama masih berlaku.');
    }

    public function test_leaving_password_blank_keeps_the_old_one(): void
    {
        $before = $this->kasir->password;

        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, ['name' => 'Nama Tanpa Ganti Password']))
            ->assertSessionHas('success');

        $fresh = $this->kasir->fresh();

        $this->assertSame($before, $fresh?->password, 'Form profil kosong justru mengganti password.');
        $this->assertTrue(Hash::check('password', $fresh?->password ?? ''));
        $this->assertSame('Nama Tanpa Ganti Password', $fresh?->name);
    }

    public function test_new_password_must_be_at_least_eight_characters(): void
    {
        $this->actingAs($this->kasir)
            ->put(route('profile.update'), $this->payload($this->kasir, [
                'current_password' => 'password',
                'password' => 'pendek',
                'password_confirmation' => 'pendek',
            ]))
            ->assertSessionHasErrors(['password' => 'Password baru minimal 8 karakter.']);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'password' => '',
            'password_confirmation' => '',
            'current_password' => '',
        ], $overrides);
    }
}
