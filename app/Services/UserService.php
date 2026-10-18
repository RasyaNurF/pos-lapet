<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Satu-satunya penulis data karyawan.
 *
 * Kenapa tidak boleh lewat controller: setiap perubahan wajib menjaga DUA
 * hal yang tidak otomatis sinkron — kolom `users.role` dan pivot Spatie
 * `model_has_roles`. Controller yang menulis `users.role` saja akan
 * menghasilkan user yang tampilannya bilang "admin" tetapi seluruh halaman
 * menolaknya 403, dan kasusnya sangat mudah terlewat saat review.
 */
class UserService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tambah karyawan baru.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'hire_date' => $data['hire_date'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->syncRoles([$data['role']]);

            return $user;
        });

        $this->audit->log('user.create', $user, null, $this->auditSnapshot($user));

        return $user;
    }

    /**
     * Ubah data karyawan, role, maupun status aktif.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $original = $user->getOriginal();

        $newRole = $data['role'] ?? $user->role;

        /*
         * Guard akun terakhir — dicek SEBELUM transaksi apa pun dijalankan.
         *
         * Kalau admin aktif terakhir bisa dinonaktifkan, diturunkan role-nya,
         * atau dihapus, sistem kehilangan seluruh akses admin tanpa cara
         * pemulihan dari UI. Pengecekan terhadap role kolom `users.role`
         * aman karena method ini adalah satu-satunya yang mengubahnya, dan
         * selalu menulis kolom + pivot bersamaan.
         */
        $losesAdmin = $user->role === 'admin' && $newRole !== 'admin';
        $becomesInactive = array_key_exists('is_active', $data) && ! $data['is_active'];

        if (($losesAdmin || $becomesInactive) && $this->isLastActiveAdmin($user)) {
            throw new RuntimeException(
                'Akun admin aktif terakhir tidak bisa dinonaktifkan atau diturunkan role-nya. '
                .'Buat atau aktifkan admin lain terlebih dahulu.',
            );
        }

        DB::transaction(function () use ($user, $data, $newRole): void {
            $payload = [
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'] ?? $user->phone,
                'address' => $data['address'] ?? $user->address,
                'hire_date' => $data['hire_date'] ?? $user->hire_date,
                'is_active' => $data['is_active'] ?? $user->is_active,
                'role' => $newRole,
            ];

            /*
             * Password hanya masuk bila benar-benar diisi. Hash lama tidak ikut
             * dilewatkan kembali — cukup bawa nilai baru bila ada, supaya
             * update profil biasa tidak pernah menyentuh kolom itu.
             */
            if (is_string($data['password'] ?? null) && $data['password'] !== '') {
                $payload['password'] = $data['password'];
            }

            $user->update($payload);

            $user->syncRoles([$newRole]);
        });

        $changes = $this->audit->changes($user, $original);

        if ($changes['old'] !== []) {
            $this->audit->log('user.update', $user, $changes['old'], $changes['new']);
        }

        return $user;
    }

    /**
     * Hapus karyawan (soft delete — riwayat shift & transaksi tetap utuh).
     */
    public function destroy(User $user): void
    {
        if ($this->isLastActiveAdmin($user)) {
            throw new RuntimeException(
                'Akun admin aktif terakhir tidak bisa dihapus. '
                .'Buat atau aktifkan admin lain terlebih dahulu.',
            );
        }

        $snapshot = $this->auditSnapshot($user);

        DB::transaction(function () use ($user): void {
            $user->delete();
        });

        $this->audit->log('user.delete', $user, $snapshot, null);
    }

    /**
     * Apakah ini satu-satunya akun yang masih punya akses admin?
     *
     * Hanya relevan bila user memang admin DAN masih aktif — akun nonaktif
     * sudah tidak bisa login, jadi tidak pernah jadi "penyimpan" akses.
     * Akun terhapus ikut tercicil dari hitungan karena query memakai global
     * scope soft delete.
     */
    private function isLastActiveAdmin(User $user): bool
    {
        if ($user->role !== 'admin' || ! $user->is_active) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $user->only([
            'name', 'email', 'phone', 'address', 'hire_date', 'role', 'is_active',
        ]);
    }
}
