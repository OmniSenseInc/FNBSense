<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->attributes->get('role'), $roles, true)) {
            return response()->json(['message' => 'Akses ditolak: role tidak berwenang.'], 403);
        }

        return $next($request);
    }
}
