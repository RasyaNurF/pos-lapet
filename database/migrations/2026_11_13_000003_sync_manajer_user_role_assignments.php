<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->where('role', 'manajer')
            ->get()
            ->each(fn (User $user) => $user->syncRoles(['manajer']));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Role assignment is repaired to match users.role; it is not reverted.
    }
};
