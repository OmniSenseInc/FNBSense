<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi JWT lintas-service secara STATELESS.
 *
 * Catalog tidak menerbitkan token dan tidak punya tabel users. Middleware ini
 * hanya memverifikasi tanda tangan token (RS256) memakai PUBLIC key IAM, lalu
 * mengekspos klaim (tenant_id, role, sub) ke request. Tidak ada query DB / IAM.
 */
class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        // parseToken()->getPayload() memverifikasi signature + klaim wajib (exp, dst).
        // Error apa pun dilempar sebagai JWTException -> jadi 401 di bootstrap/app.php.
        $payload = JWTAuth::parseToken()->getPayload();

        $tenantId = $payload->get('tenant_id');
        if (empty($tenantId)) {
            throw new JWTException('Klaim tenant_id tidak ada pada token.');
        }

        $request->attributes->set('tenant_id', $tenantId);
        $request->attributes->set('outlet_id', $payload->get('outlet_id'));
        $request->attributes->set('role', $payload->get('role'));
        $request->attributes->set('user_id', $payload->get('sub'));

        return $next($request);
    }
}
