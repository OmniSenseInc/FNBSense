<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi akses route berdasarkan klaim `role` dari JWT.
 *
 * Harus dipasang SETELAH AuthenticateJwt (membaca attribute `role`).
 * Pemakaian: ->middleware('role:owner') atau 'role:owner,manager'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->attributes->get('role');

        if ($role === null || ! in_array($role, $roles, true)) {
            return response()->json(['message' => 'Akses ditolak: role tidak berwenang.'], 403);
        }

        return $next($request);
    }
}
