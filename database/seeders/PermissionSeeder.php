<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Seed semua permission dari registry config/modules.php.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $modules = config('modules.modules', []);

        foreach ($modules as $module => $config) {
            foreach ($config['actions'] as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }
    }
}
