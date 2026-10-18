<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Jejak audit bersifat sensitif — hanya role dengan `audit.view` (admin).
 * Tidak ada create/update/delete: log bersifat append-only.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }
}
