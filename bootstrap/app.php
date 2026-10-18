<?php

use App\Http\Middleware\EnsureShiftOpen;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'shift.open' => EnsureShiftOpen::class,
        ]);

        /*
         | Penonaktifan berlaku di mana pun, jadi ditempel ke seluruh grup `web`.
         |
         | Berbeda dengan `shift.open` yang sengaja per-rute (hanya POS yang
         | butuh shift terbuka), mencatatkan middleware ini per-rute membuka
         | celah yang tidak terlihat: satu rute lupa diberi alias, sesi user
         | nonaktif tetap hidup di sana.
         */
        $middleware->web(append: [EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
