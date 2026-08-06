<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Lihat catatan panjang di services/ordering/bootstrap/app.php.
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        // Inventory = API-only: semua request API diperlakukan sebagai JSON.
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // Alias middleware auth lintas-service.
        $middleware->alias([
            'jwt' => \App\Http\Middleware\AuthenticateJwt::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Semua error token JWT (kedaluwarsa/rusak/tidak ada) -> 401 bersih,
        // bukan 500 yang membocorkan stack trace ke klien.
        $exceptions->render(function (JWTException $e, Request $request) {
            return response()->json(['message' => 'Token tidak valid atau sudah kedaluwarsa.'], 401);
        });
    })->create();
