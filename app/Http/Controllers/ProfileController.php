<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Profil sendiri — terbuka bagi SEMUA karyawan, tanpa `users.*` (plan.md TAHAP 10).
 *
 * Berbeda dengan UserController yang mengelola orang lain, controller ini tidak
 * pernah menerima user lain: targetnya selalu `$request->user()`. Tidak ada
 * parametrik route `{user}` yang bisa dimanipulasi untuk menyentuh akun orang.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();

        $this->authorize('update', $user);

        return view('profile.edit', ['profile' => $user]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();

        $this->authorize('update', $user);

        /*
         * Tetap lewat UserService, bukan $user->update() langsung: kolom
         * `users.role` dan pivot Spatie tidak boleh pernah disentuh lewat
         * jalur lain, dan audit `user.update` tetap tercatat. Role & status
         * tidak ikut berubah karena keduanya tidak ada di validated().
         */
        try {
            $this->users->update($user, $request->validated());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Profil berhasil diperbarui.');
    }
}
