<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi akses route berdasarkan role user pemilik token.
 *
 * Harus dipasang SETELAH `auth:api`. Beda dari EnsureRole milik service hilir
 * (Catalog/Ordering) yang membaca klaim dari `$request->attributes`: di IAM
 * guard "api" sudah menghidupkan model User asli, jadi role dibaca dari DB —
 * user yang baru saja diturunkan haknya tidak lolos karena tokennya masih lama.
 *
 * Sekalian menutup jendela suspend: `is_active` dicek ULANG dari DB di sini,
 * bukan dari klaim token. Tanpa ini, kasir yang baru dinonaktifkan masih bisa
 * memakai token lamanya sampai TTL 15 menit habis. Karena guard "api" memang
 * sudah menghidupkan model User dari DB, cek ini gratis — nol query tambahan.
 * Yang tersisa cuma endpoint ber-`auth:api` polos (/auth/me, /auth/logout),
 * dan itu memang tak berbahaya bagi akun yang disuspend.
 *
 * Pemakaian: ->middleware('role:owner') atau 'role:owner,cashier'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = auth('api')->user();

        if (! $user instanceof User || ! in_array($user->role?->value, $roles, true)) {
            return response()->json(['message' => 'Akses ditolak: role tidak berwenang.'], 403);
        }

        // Akun yang sudah dinonaktifkan kehilangan seluruh hak istimewanya
        // seketika, tak perlu menunggu tokennya kedaluwarsa.
        if (! $user->is_active) {
            return response()->json(['message' => 'Akun kamu sudah dinonaktifkan.'], 403);
        }

        return $next($request);
    }
}
