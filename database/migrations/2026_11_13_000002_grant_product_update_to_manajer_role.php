<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('products.update', 'web');
        $managerRole = Role::findOrCreate('manajer', 'web');
        $managerRole->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $managerRole = Role::query()
            ->where('name', 'manajer')
            ->where('guard_name', 'web')
            ->first();

        $permission = Permission::query()
            ->where('name', 'products.update')
            ->where('guard_name', 'web')
            ->first();

        if ($managerRole !== null && $permission !== null) {
            $managerRole->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
