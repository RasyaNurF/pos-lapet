<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    /** Batas percobaan login gagal per email+IP dalam 1 menit. */
    private const int LOGIN_MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        // Layer 2 Access Guard — anti brute force.
        $throttleKey = 'login:'.sha1(strtolower($request->input('email')).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ])->status(429);
        }

        $remember = $request->boolean('remember');

        /*
         * Akun nonaktif tidak boleh masuk sama sekali.
         *
         * `is_active` ikut jadi syarat attempt — bukan dicek terpisah sesudah
         * login — supaya tidak ada jendela sesaat di mana user nonaktif sudah
         * ter-authenticate tetapi belum tersaring.
         *
         * Pesan sengaja disamakan dengan kredensial salah. Pesan berbeda akan
         * memberi tahu siapa pun bahwa alamat email tertentu terdaftar dan
         * sedang dinonaktifkan — penggunaan klasik untuk user enumeration.
         */
        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
