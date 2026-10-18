<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Role `manajer` dibuat DI SINI, bukan di RoleSeeder.
     *
     * `RoleSeeder` tidak pernah dijalankan otomatis oleh `php artisan migrate`
     * — hanya oleh `db:seed`. Mengandalkannya berarti role manajer bisa
     * terlewat di VPS lantaran langkah seeding-nya gampang terlupa, dan
     * manajer yang dibuat admin nanti jadi tanpa permission sama sekali.
     *
     * Permission ikut dibuat di sini karena `Role::givePermissionTo()` akan
     * melempar PermissionDoesNotExist bila namanya belum terdaftar. Ini
     * sengaja mengulang pekerjaan PermissionSeeder — keduanya idempoten
     * (findOrCreate), jadi tidak ada duplikat bila seeding berjalan sesudahnya.
     *
     * Sengaja TANPA pengecekan driver: berbeda dengan guard CHECK/FK di
     * migrasi lain, role ini adalah data aplikasi yang juga dibutuhkan suite
     * test di SQLite.
     */
    public function up(): void
    {
        // Permission seluruh registry — prasyarat agar manajer bisa digrant.
        foreach (config('modules.modules', []) as $module => $config) {
            foreach ($config['actions'] as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }

        $definition = config('modules.roles.manajer', []);

        if ($definition === []) {
            return;
        }

        $role = Role::findOrCreate('manajer', 'web');
        $role->syncPermissions($definition['permissions']);

        // Lupakan cache Spatie — tanpa ini permission lama masih berlaku
        // sampai proses berikutnya, terasa sebagai "perubahan tidak bekerja".
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        /*
         * Pakai query builder, bukan Role::findByName(): metode itu melempar
         * PermissionDoesNotExist bila role sudah tidak ada — bukan null — sehingga
         * rollback kedua akan gagal. Pivot terhapus otomatis lewat cascadeOnDelete.
         */
        Role::query()
            ->where('name', 'manajer')
            ->where('guard_name', 'web')
            ->delete();
    }
};
