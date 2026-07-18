<?php

use App\Exceptions\CatalogUnavailableException;
use App\Exceptions\ProductNotOrderableException;
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
        // Ordering = API-only: semua request API diperlakukan sebagai JSON.
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

        // Produk dipesan tapi tak ada di menu tenant (tak dikenal / tak tersedia /
        // milik tenant lain) -> 422, bukan 500. Ini pertahanan skrutini #3.
        $exceptions->render(function (ProductNotOrderableException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        // Catalog (sumber harga) tak bisa dihubungi -> 503 "menu sedang tak bisa
        // diakses", bukan 500 yang membocorkan dependensi internal tumbang.
        $exceptions->render(function (CatalogUnavailableException $e, Request $request) {
            return response()->json(['message' => 'Menu sedang tidak dapat diakses. Coba lagi sebentar lagi.'], 503);
        });
    })->create();
