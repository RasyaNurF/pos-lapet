<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Seed role admin (semua permission) & kasir (dari config/modules.php).
     */
    public function run(): void
    {
        $registry = config('modules.roles', []);

        // Admin mendapat semua permission.
        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions(Permission::all());

        // Kasir mendapat permission sesuai registry.
        foreach ($registry as $roleName => $config) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($config['permissions']);
        }
    }
}
