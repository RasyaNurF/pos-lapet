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

        /*
         * Menonaktifkan karyawan yang shift-nya masih terbuka mengubur shift itu
         * selamanya. Satu-satunya yang bisa menutup shift adalah pemiliknya
         * sendiri (ShiftController::close memakai `$request->user()`), dan
         * setelah dinonaktifkan ia bahkan tidak bisa login untuk menutupnya:
         * end_cash tidak pernah dihitung, selisih kas tak pernah selesai, dan
         * bila kelak diaktifkan kembali ia langsung membuka POS di atas shift
         * berhari-hari yang lalu.
         *
         * Pesan ini menuntut urutan kerja yang memang benar: hitung kas dulu,
         * baru cabut akses.
         */
        if ($becomesInactive && $this->hasUnclosedShift($user)) {
            throw new RuntimeException(
                'Shift karyawan ini masih terbuka. Minta ia menutup shift (menghitung kas) '
                .'terlebih dahulu sebelum akunnya dinonaktifkan.',
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

        if ($this->hasUnclosedShift($user)) {
            throw new RuntimeException(
                'Shift karyawan ini masih terbuka. Minta ia menutup shift (menghitung kas) '
                .'terlebih dahulu sebelum akunnya dihapus.',
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
     * Apakah masih ada shift yang belum ditutup?
     *
     * Dua-duanya diperiksa — kolom `users.shift_status` dan baris `shifts`
     * tanpa `end_time` — karena keduanya sumber kebenaran terpisah, persis
     * seperti `role` vs pivot Spatie. Bila salah satunya bilang terbuka, ada
     * pekerjaan yang belum beres, dan menonaktifkan hanya akan menguburnya.
     */
    private function hasUnclosedShift(User $user): bool
    {
        return $user->hasOpenShift() || $user->currentShift()->exists();
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
