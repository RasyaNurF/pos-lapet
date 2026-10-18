<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Daftar karyawan: admin saja.
 *
 * `kasir` dan `manajer` sama sekali tidak mendapat users.* (plan.md Tahap 10),
 * jadi seluruh metode di sini pada praktiknya hanya mengembalikan true bagi
 * admin. Tetap ditulis eksplisit agar aturan itu terbaca dari kode, bukan
 * dari kebetulan susunan permission.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $employee): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $employee): bool
    {
        return $user->can('users.update');
    }

    public function delete(User $user, User $employee): bool
    {
        return $user->can('users.delete');
    }
}
