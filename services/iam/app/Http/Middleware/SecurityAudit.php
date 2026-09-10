<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rekam peristiwa keamanan di IAM ke tabel security_events.
 *
 * Dipasang di route PUBLIK yang rawan serangan (login, register): status
 * respons yang keluar menentukan apa yang dicatat — 401 = kredensial gagal,
 * 429 = throttle bening, 403 = registrasi ditutup. Tabel ini yang jadi
 * sumber kebenaran "ada yang nyoba-nyoba", bukan log file yang hilang ikut
 * container. Gagal menulis audit TIDAK boleh menumbangkan request login.
 */
class SecurityAudit
{
    public function handle(Request $request, Closure $next): Response
    {
        $res = $next($request);
        $status = $res->getStatusCode();

        $type = match (true) {
            $request->is('api/auth/login') && $status === 401 => 'login_failed',
            $request->is('api/auth/login') && $status === 429 => 'login_throttled',
            $request->is('api/auth/register') && $status === 403 => 'register_blocked',
            $request->is('api/auth/register') && $status === 429 => 'register_throttled',
            $request->is('api/auth/register') && $status >= 200 && $status < 300 => 'register_ok',
            default => null,
        };

        if ($type === null) {
            return $res;
        }

        try {
            DB::table('security_events')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'type' => $type,
                'email' => (string) $request->input('email', ''),
                'ip' => $request->ip(),
                'endpoint' => $request->path(),
                'meta' => json_encode(
                    ['user_agent' => mb_substr((string) $request->userAgent(), 0, 200)],
                    JSON_UNESCAPED_UNICODE,
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit gagal disimpan — jangan pernah menggagalkan login karenanya.
        }

        return $res;
    }
}