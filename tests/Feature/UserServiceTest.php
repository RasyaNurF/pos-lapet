<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Tes inti Tahap 10 — dua jebakan yang paling merusak bila terlewat.
 *
 * 1. Role punya dua sumber kebenaran: kolom `users.role` dan pivot Spatie.
 *    Bila keduanya ditulis terpisah, user tampil sebagai admin tetapi seluruh
 *    halamannya menolak 403.
 * 2. Admin aktif terakhir bisa mengunci dirinya sendiri, meninggalkan
 *    sistem tanpa jalan keluar.
 */
class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->service = app(UserService::class);
    }

    // ------------------------------------------------------------------
    // Jebakan #1 — kolom role dan pivot Spatie
    // ------------------------------------------------------------------

    public function test_create_writes_role_column_and_spatie_pivot_together(): void
    {
        $user = $this->service->create([
            'name' => 'Budi Santoso',
            'email' => 'budi@lapet.test',
            'password' => 'password',
            'role' => 'manajer',
            'phone' => '08123456789',
            'address' => 'Jl. Kenanga No. 7',
            'hire_date' => '2026-02-01',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Budi Santoso',
            'role' => 'manajer',
            'phone' => '08123456789',
            'address' => 'Jl. Kenanga No. 7',
            'is_active' => true,
        ]);

        $fresh = $user->fresh();

        // Pivot Spatie — satu-satunya sumber untuk can().
        $this->assertTrue($fresh?->hasRole('manajer'), 'Pivot Spatie tidak tertulis.');

        // Bukan sekadar nama role: permission-nya benar-benar mengalir.
        $this->assertTrue($fresh?->can('reports.view'), 'Role ada tapi permission kosong.');
        $this->assertFalse($fresh?->can('products.delete'), 'Manajer tidak boleh menghapus produk.');
    }

    public function test_changing_role_updates_column_and_pivot_together(): void
    {
        $user = User::factory()->create(['role' => 'kasir']);
        $user->assignRole('kasir');

        $updated = $this->service->update($user, ['role' => 'manajer']);

        $this->assertSame('manajer', $updated->role, 'Kolom users.role tidak ikut berubah.');

        $fresh = $updated->fresh();
        $this->assertTrue($fresh?->hasRole('manajer'), 'Pivot tidak pindah ke role baru.');
        $this->assertFalse($fresh?->hasRole('kasir'), 'Pivot lama menempel — dua sumber kebenaran.');

        // Permission mengikuti pivot, bukan kolom.
        $this->assertTrue($fresh?->can('reports.view'));
        $this->assertFalse($fresh?->can('sales.create'));
    }

    /**
     * Regresi utama: mengubah hanya nama/telepon tidak boleh merusak role.
     * Bila update parsial menyentuh pivot, kasir tiba-tiba kehilangan seluruh
     * aksesnya hanya karena nomor telepon diperbarui.
     */
    public function test_profile_only_update_leaves_pivot_untouched(): void
    {
        $user = User::factory()->create(['role' => 'kasir']);
        $user->assignRole('kasir');

        $updated = $this->service->update($user, [
            'name' => 'Kasir Baru',
            'phone' => '089998887777',
        ]);

        $this->assertSame('Kasir Baru', $updated->name);
        $this->assertSame('089998887777', $updated->phone);
        $this->assertSame('kasir', $updated->role);
        $this->assertTrue($updated->hasRole('kasir'));
        $this->assertTrue($updated->can('sales.create'), 'Akses kasir hilang karena update profil.');
    }

    // ------------------------------------------------------------------
    // Jebakan #3 — admin aktif terakhir mengunci diri sendiri
    // ------------------------------------------------------------------

    public function test_cannot_deactivate_the_last_active_admin(): void
    {
        $admin = $this->admin();

        $this->assertGuarded(
            fn () => $this->service->update($admin, ['is_active' => false]),
            'admin aktif terakhir tidak bisa dinonaktifkan',
        );

        // Guard berlaku SEBELUM menulis — tidak boleh ada sisa tulisan parsial.
        $fresh = $admin->fresh();
        $this->assertTrue($fresh?->is_active, 'Akun masih nonaktif walau guard melempar.');
        $this->assertSame('admin', $fresh?->role);
        $this->assertTrue($fresh?->hasRole('admin'));
    }

    public function test_cannot_demote_the_last_active_admin(): void
    {
        $admin = $this->admin();

        $this->assertGuarded(
            fn () => $this->service->update($admin, ['role' => 'kasir']),
            'admin aktif terakhir',
        );

        $fresh = $admin->fresh();
        $this->assertSame('admin', $fresh?->role);
        $this->assertTrue($fresh?->hasRole('admin'), 'Pivot ikut terhapus walau guard melempar.');
        $this->assertTrue($fresh?->can('users.view') ?? false);
    }

    public function test_cannot_delete_the_last_active_admin(): void
    {
        $admin = $this->admin();

        $this->assertGuarded(
            fn () => $this->service->destroy($admin),
            'admin aktif terakhir tidak bisa dihapus',
        );

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_an_admin_can_be_deactivated_when_another_active_admin_exists(): void
    {
        $this->admin();
        $second = $this->admin();

        $this->service->update($second, ['is_active' => false]);

        $this->assertFalse($second->fresh()?->is_active);

        // Admin pertama masih utuh sebagai akses tersisa.
        $this->assertTrue(User::query()->where('role', 'admin')->where('is_active', true)->exists());
    }

    /**
     * Admin yang sudah nonaktif tidak bisa login, jadi tidak boleh dihitung
     * sebagai "penyimpan" akses — kalau dihitung, guard akan melebar dan
     * mengunci semua perubahan pada akun lain.
     */
    public function test_an_inactive_admin_does_not_satisfy_the_admin_quorum(): void
    {
        $active = $this->admin(['email' => 'aktif@lapet.test']);
        $this->admin(['email' => 'nonaktif@lapet.test', 'is_active' => false]);

        $this->assertGuarded(
            fn () => $this->service->update($active, ['is_active' => false]),
            'admin aktif terakhir',
        );

        // Admin yang sudah nonaktif tetap bebas dihapus.
        $inactive = User::query()->where('email', 'nonaktif@lapet.test')->firstOrFail();
        $this->service->destroy($inactive);

        $this->assertSoftDeleted('users', ['id' => $inactive->id]);
    }

    // ------------------------------------------------------------------
    // Perilaku CRUD biasa
    // ------------------------------------------------------------------

    public function test_update_persists_employee_fields(): void
    {
        $user = User::factory()->create(['role' => 'kasir']);

        $updated = $this->service->update($user, [
            'name' => 'Siti',
            'phone' => '08111222333',
            'address' => 'Jl. Melati No. 3',
            'hire_date' => '2026-03-20',
        ]);

        $this->assertSame('Siti', $updated->name);
        $this->assertSame('08111222333', $updated->phone);
        $this->assertSame('Jl. Melati No. 3', $updated->address);
        $this->assertSame('2026-03-20', $updated->hire_date->format('Y-m-d'));
    }

    public function test_update_leaves_password_untouched_when_not_provided(): void
    {
        $user = $this->admin();
        $before = $user->password;

        $this->service->update($user, ['name' => 'Nama Baru']);

        $after = $user->fresh()?->password;

        $this->assertSame($before, $after, 'Update profil biasa tidak boleh mengganti password.');
        $this->assertTrue(Hash::check('password', $after ?? ''), 'Password lama sudah tidak berlaku.');
    }

    public function test_update_changes_password_when_provided(): void
    {
        $user = $this->admin();

        $this->service->update($user, ['password' => 'rahasia-baru']);

        $hash = $user->fresh()?->password;

        $this->assertTrue(Hash::check('rahasia-baru', $hash ?? ''), 'Password baru tidak tersimpan.');
        $this->assertFalse(Hash::check('password', $hash ?? ''), 'Password lama masih berlaku.');
    }

    public function test_operations_write_an_audit_trail(): void
    {
        $user = $this->service->create([
            'name' => 'Audit User',
            'email' => 'audit.user@lapet.test',
            'password' => 'password',
            'role' => 'kasir',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.create']);

        $this->service->update($user, ['phone' => '08123000111']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.update']);

        $this->service->destroy($user);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.delete']);

        // Jejak audit tidak boleh membocorkan hash password apa pun.
        $newValues = (string) DB::table('audit_logs')
            ->where('action', 'user.create')
            ->value('new_values');

        $this->assertStringNotContainsString('$2y$', $newValues, 'Hash password bocor ke jejak audit.');
        $this->assertStringNotContainsString('$argon', $newValues, 'Hash password bocor ke jejak audit.');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function admin(array $attributes = []): User
    {
        $admin = User::factory()->create(array_merge(['role' => 'admin'], $attributes));
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * Jalankan callable, wajib melempar RuntimeException berisi $needle.
     *
     * Memakai try/catch, bukan expectException, supaya test bisa lanjut
     * memastikan tidak ada tulisan parsial yang tertinggal.
     */
    private function assertGuarded(callable $callback, string $needle): void
    {
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Seharusnya melempar RuntimeException berisi \"{$needle}\", tapi tidak.");
    }
}
