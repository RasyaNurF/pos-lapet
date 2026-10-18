<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mematikan sesi tiap request bila akun dinonaktifkan selagi masih login (plan.md TAHAP 10).
 *
 * Tanpa ini, kasir yang baru saja dinonaktifkan admin akan tetap bisa
 * bertransaksi sampai sesinya berakhir sendiri — logout-nya jadi soal waktu,
 * bukan soal keputusan admin. Blokir di `Auth::attempt()` hanya menutup pintu
 * masuk; middleware ini yang mengusir yang sudah berada di dalam.
 *
 * Didaftarkan sebagai anggota grup `web`, bukan per-rute seperti `shift.open`:
 * POS-lah yang butuh shift terbuka, sedangkan penonaktifan berlaku di mana
 * pun. Mendaftarkan per-rute berarti satu rute yang terlupa = celah yang
 * tidak terlihat.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Belum masuk sesi (halaman login, dst.) — biarkan alur auth sendiri
        // yang menentukan, dan jangan sampai middleware ini ikut me-logout.
        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::logout();

        /*
         * `invalidate()` + `regenerateToken()` bukan formalitas: tanpa
         * invalidate, cookie sesi lama masih membawa penanda login yang sama
         * dan langsung terhidup kembali di request berikutnya. Tanpa
         * regenerateToken, CSRF token halaman sebelumnya masih berlaku.
         */
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('error', 'Akun Anda telah dinonaktifkan. Hubungi admin untuk mengaktifkan kembali.');
    }
}
