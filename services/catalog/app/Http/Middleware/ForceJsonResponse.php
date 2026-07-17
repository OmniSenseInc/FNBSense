<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memaksa setiap request API diperlakukan sebagai JSON.
 *
 * Catalog adalah service API-only: tanpa ini, request tanpa header
 * "Accept: application/json" dianggap request web biasa, sehingga saat
 * gagal auth Laravel mencoba redirect ke route "login" (yang tidak ada)
 * dan melempar 500. Dengan memaksa Accept JSON, error auth menjadi
 * 401 JSON yang rapi.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
