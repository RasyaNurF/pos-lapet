<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CRUD karyawan (plan.md Tahap 10, commit 5).
 *
 * Dua hal yang paling sering putus di sini, keduanya diuji eksplisit:
 * 1. Role hanya tercatat di kolom tetapi tidak di pivot Spatie → user terlihat
 *    punya role tapi semua halamannya menolak.
 * 2. Mengosongkan password saat edit ternyata dianggap validasi gagal →
 *    profil tidak bisa diperbarui sama sekali.
 */
class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->admin = User::factory()->create(['name' => 'Admin Utama', 'role' => 'admin']);
        $this->admin->assignRole('admin');
    }

    // ------------------------------------------------------------------
    // Akses — daftar karyawan admin saja
    // ------------------------------------------------------------------

    public function test_admin_can_view_the_employee_list(): void
    {
        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Karyawan');
    }

    public function test_kasir_cannot_view_the_employee_list(): void
    {
        $this->actingAs($this->kasir())
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_manajer_cannot_view_the_employee_list(): void
    {
        $manajer = User::factory()->create(['role' => 'manajer']);
        $manajer->assignRole('manajer');

        $this->actingAs($manajer)
            ->get(route('users.index'))
            ->assertForbidden();

        $this->actingAs($manajer)
            ->post(route('users.store'), $this->validPayload())
            ->assertForbidden();
    }

    public function test_kasir_does_not_see_the_employee_menu(): void
    {
        $this->actingAs($this->kasir())
            ->get(route('customers.index'))
            ->assertOk()
            ->assertDontSee('Karyawan');
    }

    public function test_admin_sees_the_employee_menu(): void
    {
        $this->actingAs($this->admin)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Karyawan');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_admin_can_create_a_karyawan(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $user = User::query()->where('email', 'baru@lapet.test')->firstOrFail();

        $this->assertSame('Karyawan Baru', $user->name);
        $this->assertSame('kasir', $user->role);
        $this->assertSame('081234567890', $user->phone);
        $this->assertSame('Jl. Melati No. 3', $user->address);
        $this->assertSame('2026-02-02', $user->hire_date->format('Y-m-d'));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('rahasia123', $user->password));

        // Jebakan #1: kolom saja tidak cukup — pivot wajib terisi.
        $this->assertTrue($user->hasRole('kasir'), 'Role terlihat ada di kolom tapi pivot Spatie kosong.');
        $this->assertTrue($user->can('sales.create'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.create']);
    }

    public function test_create_validates_the_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), [])
            ->assertSessionHasErrors(['name', 'email', 'password', 'role', 'is_active']);
    }

    public function test_create_rejects_a_role_that_is_not_assignable(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload(['role' => 'superuser']))
            ->assertSessionHasErrors(['role' => 'Role yang dipilih tidak dikenal.']);
    }

    public function test_create_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dupe@lapet.test']);

        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload(['email' => 'dupe@lapet.test']))
            ->assertSessionHasErrors(['email' => 'Email ini sudah dipakai karyawan lain.']);
    }

    /**
     * Baris yang sudah soft delete masih menempati indeks unique kolom email,
     * jadi validasi HARUS menolaknya — kalau lolos, permintaan berikutnya
     * meledak jadi error 500 di level database.
     */
    public function test_email_of_a_soft_deleted_karyawan_cannot_be_reused(): void
    {
        User::factory()->create(['email' => 'bekas@lapet.test'])->delete();

        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->validPayload(['email' => 'bekas@lapet.test']))
            ->assertSessionHasErrors(['email' => 'Email ini sudah dipakai karyawan lain.']);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_admin_can_update_a_karyawan(): void
    {
        $employee = $this->kasir();

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => $employee->email,
                'name' => 'Nama Baru',
                'phone' => '08999000111',
                'role' => 'manajer',
                'is_active' => 1,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $employee->fresh();

        $this->assertSame('Nama Baru', $fresh?->name);
        $this->assertSame('08999000111', $fresh?->phone);
        $this->assertSame('manajer', $fresh?->role);

        $this->assertTrue($fresh?->hasRole('manajer'), 'Pivot tidak ikut pindah saat role diganti.');
        $this->assertFalse($fresh?->hasRole('kasir'));
        $this->assertTrue($fresh?->can('reports.view'));
        $this->assertFalse($fresh?->can('sales.create'));
    }

    /**
     * Jebakan kedua: form edit selalu mengirim kolom password meski kosong.
     * Tanpa normalisasi, `''` gagal aturan `min:8` dan seluruh edit macet.
     */
    public function test_editing_without_a_password_keeps_the_old_one(): void
    {
        $employee = $this->kasir();
        $before = $employee->password;

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => $employee->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => 'kasir',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $employee->fresh();

        $this->assertSame($before, $fresh?->password, 'Mengosongkan form justru mengganti password.');
        $this->assertTrue(Hash::check('password', $fresh?->password ?? ''));
    }

    public function test_editing_with_a_password_changes_it(): void
    {
        $employee = $this->kasir();

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => $employee->email,
                'password' => 'ganti-baru-9',
                'password_confirmation' => 'ganti-baru-9',
                'role' => 'kasir',
            ]))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('ganti-baru-9', $employee->fresh()?->password ?? ''));
    }

    public function test_update_rejects_the_email_of_another_karyawan(): void
    {
        $employee = $this->kasir();
        User::factory()->create(['email' => 'orang.lain@lapet.test']);

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => 'orang.lain@lapet.test',
                'role' => 'kasir',
            ]))
            ->assertSessionHasErrors(['email' => 'Email ini sudah dipakai karyawan lain.']);
    }

    public function test_update_allows_keeping_its_own_email(): void
    {
        $employee = $this->kasir();

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => $employee->email,
                'role' => 'kasir',
            ]))
            ->assertSessionHas('success');
    }

    public function test_a_soft_deleted_karyawan_cannot_be_edited(): void
    {
        $employee = $this->kasir();
        $employee->delete();

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => $employee->email,
                'role' => 'kasir',
            ]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Status aktif & guard akun terakhir
    // ------------------------------------------------------------------

    public function test_admin_can_deactivate_a_karyawan(): void
    {
        $employee = $this->kasir();

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => $employee->email,
                'role' => 'kasir',
                'is_active' => 0,
            ]))
            ->assertSessionHas('success');

        $this->assertFalse($employee->fresh()?->is_active);
    }

    /**
     * Guard di UserService melempar RuntimeException. Controller wajib
     * mengubahnya jadi flash error — kalau lolos, admin melihat halaman
     * 500 padahal ia sedang mencoba sesuatu yang memang dilarang.
     */
    public function test_deactivating_the_last_admin_flashes_an_error_not_a_500(): void
    {
        $this->actingAs($this->admin)
            ->put(route('users.update', $this->admin), $this->validPayload([
                'email' => $this->admin->email,
                'role' => 'admin',
                'is_active' => 0,
            ]))
            ->assertStatus(302)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'is_active' => 1,
            'role' => 'admin',
        ]);
    }

    /**
     * Menonaktifkan kasir yang shift-nya masih terbuka mengunci shift itu
     * selamanya — tidak ada jalur admin untuk menutup shift orang lain.
     * Guard harus sampai ke admin sebagai pesan biasa, bukan error 500.
     */
    public function test_deactivating_an_employee_with_an_open_shift_flashes_an_error(): void
    {
        $employee = $this->kasir();

        $employee->update(['shift_status' => 'open']);
        Shift::create([
            'user_id' => $employee->id,
            'start_time' => now(),
            'start_cash' => 50000,
            'total_sales' => 0,
        ]);

        $this->actingAs($this->admin)
            ->put(route('users.update', $employee), $this->validPayload([
                'email' => $employee->email,
                'role' => 'kasir',
                'is_active' => 0,
            ]))
            ->assertStatus(302)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $employee->id, 'is_active' => 1]);
    }

    public function test_deleting_the_last_admin_flashes_an_error_not_a_500(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->admin))
            ->assertStatus(302)
            ->assertSessionHas('error');

        // assertDatabaseHas membaca mentah, jadi baris ter-soft-delete tetap
        // terlihat — inilah yang membuat asersi ini tidak bisa lolos palsu.
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'deleted_at' => null]);
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_admin_can_soft_delete_a_karyawan(): void
    {
        $employee = $this->kasir();

        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $employee))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSoftDeleted('users', ['id' => $employee->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.delete']);

        // Hilang dari daftar, tapi tetap ada bila perlu ditelusuri.
        $this->assertNull(User::find($employee->id));
        $this->assertNotNull(User::withTrashed()->find($employee->id));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function kasir(): User
    {
        $kasir = User::factory()->create([
            'name' => 'Kasir Target',
            'role' => 'kasir',
            'shift_status' => 'closed',
        ]);
        $kasir->assignRole('kasir');

        return $kasir;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Karyawan Baru',
            'email' => 'baru@lapet.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'role' => 'kasir',
            'phone' => '081234567890',
            'address' => 'Jl. Melati No. 3',
            'hire_date' => '2026-02-02',
            'is_active' => 1,
        ], $overrides);
    }
}
