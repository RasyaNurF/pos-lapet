<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wajib buka shift sebelum akses /pos (plan.md TAHAP 3).
 */
class EnsureShiftOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasOpenShift()) {
            return redirect()->route('shifts.open.form')
                ->with('error', 'Buka shift terlebih dahulu sebelum menggunakan POS.');
        }

        return $next($request);
    }
}
