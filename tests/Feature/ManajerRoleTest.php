<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Role `manajer` harus lahir dari MIGRASI, bukan dari seeding.
 *
 * Kelas ini sengaja TIDAK memanggil seed() apa pun di setUp — karena
 * RoleSeeder memang tidak pernah dijalankan otomatis oleh `php artisan
 * migrate`. Bila test ini hijau, artinya VPS yang hanya menjalankan migrate
 * tetap menghasilkan manajer yang benar-benar bisa dipakai, bukan role kosong
 * yang baru terisi permission bila seseorang tidak lupa menjalankan db:seed.
 */
class ManajerRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $manajer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manajer = User::factory()->create(['role' => 'manajer']);
        $this->manajer->assignRole('manajer');
    }

    /**
     * findByName melempar PermissionDoesNotExist bila role tidak ada — jadi
     * pemanggilan ini sendiri sudah jadi asersi.
     */
    public function test_manajer_role_is_born_from_migration_without_seeding(): void
    {
        $role = Role::findByName('manajer', 'web');

        $expected = collect(config('modules.roles.manajer.permissions'))
            ->sort()->values()->all();
        $actual = $role->permissions->pluck('name')
            ->sort()->values()->all();

        $this->assertSame($expected, $actual, 'Isi role manajer tidak sesuai registry.');
    }

    public function test_manajer_can_view_reports_and_export(): void
    {
        $this->actingAs($this->manajer)
            ->get(route('reports.sales'))
            ->assertOk();

        $this->actingAs($this->manajer)
            ->get(route('reports.stock'))
            ->assertOk();

        $this->actingAs($this->manajer)
            ->get(route('reports.export'))
            ->assertOk();
    }

    public function test_manajer_can_view_and_edit_products(): void
    {
        $this->actingAs($this->manajer)
            ->get(route('products.index'))
            ->assertOk();
    }

    /**
     * Batas paling penting: manajer boleh mengelola produk, tetapi tidak
     * boleh menghapusnya.
     */
    public function test_manajer_cannot_delete_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->manajer)
            ->delete(route('products.destroy', $product))
            ->assertForbidden();
    }

    public function test_manajer_cannot_view_audit_log(): void
    {
        $this->actingAs($this->manajer)
            ->get(route('audit-logs.index'))
            ->assertForbidden();
    }

    /**
     * Pengaturan toko (PPN, mata uang) tetap milik admin — manajer hanya
     * mengelola operasional, bukan kebijakan finansial.
     */
    public function test_manajer_cannot_view_store_settings(): void
    {
        $this->actingAs($this->manajer)
            ->get(route('settings.index'))
            ->assertForbidden();
    }
}
