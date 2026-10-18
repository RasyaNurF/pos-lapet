<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Daftar karyawan — tipis: pencarian & tampilan saja.
 *
 * Seluruh tulisan data lewat UserService, bukan `User::create()` di sini.
 * Menulis `users.role` langsung dari controller adalah persis cara kolom itu
 * dan pivot Spatie bisa berbeda (lihat docblock UserService).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $list = User::query()
            ->when($request->filled('search'), fn ($q) => $q->search((string) $request->input('search')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $list,
            'roles' => User::assignableRoles(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $this->users->create($request->validated());

        return back()->with('success', 'Karyawan berhasil ditambahkan.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        // Guard akun terakhir melempar RuntimeException — kembalikan sebagai
        // pesan biasa, bukan error 500 yang tidak bisa ditangani admin.
        try {
            $this->users->update($user, $request->validated());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Karyawan berhasil diperbarui.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        try {
            $this->users->destroy($user);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Karyawan berhasil dihapus.');
    }
}
