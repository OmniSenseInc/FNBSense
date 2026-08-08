<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth service-to-service via shared-secret header `X-Service-Token`.
 *
 * Disalin dari services/catalog — pola yang sama, alasan yang sama, dan
 * sengaja TIDAK diangkat jadi paket bersama: dua salinan tiga puluh baris
 * lebih murah daripada satu paket yang harus diversikan di tujuh service.
 *
 * Dipakai `POST /api/availability`, yang dipanggil Ordering saat pelanggan
 * menyusun pesanan — tak ada user JWT di sana; yang memesan adalah orang tanpa
 * akun. Fail-closed: secret belum dikonfigurasi ATAU tak cocok -> 401.
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
