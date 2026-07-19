<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth service-to-service via shared-secret header `X-Service-Token`.
 *
 * Dipakai endpoint internal (mis. GET /api/recipe untuk Inventory) yang dipanggil
 * dari consumer event — tak ada user JWT. Bukan pengganti JWT untuk request user.
 * Fail-closed: secret belum dikonfigurasi ATAU tak cocok -> 401.
 */
class AuthenticateServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.internal_token');
        $provided = $request->header('X-Service-Token');

        // Bandingan timing-safe; tolak kalau secret kosong (jangan pernah allow-by-default).
        if (empty($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Service token tidak valid.'], 401);
        }

        return $next($request);
    }
}
