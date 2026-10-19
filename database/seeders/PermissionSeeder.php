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

        /*
         * RoleSeeder mengambil daftar permission sebagai snapshot, sehingga
         * permission yang baru ditambahkan ke config/modules.php tidak akan
         * dipegang role mana pun sampai RoleSeeder dijalankan ulang. Akibatnya
         * permission itu sah di database tapi @can selalu menolak — menu
         * menghilang tanpa error. Menyinkronkan di sini membuatnya mustahil
         * terjadi: membuat permission selalu berarti memberikannya ke role.
         */
        $this->call(RoleSeeder::class);
    }
}
