<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\StaffController;
use App\Http\Middleware\SecurityAudit;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Publik — dibatasi rate limit (6 request/menit per IP) untuk membendung
    // brute-force password & spam registrasi.
    Route::post('register', [AuthController::class, 'register'])->middleware(['throttle:6,1', SecurityAudit::class]);
    Route::post('login', [AuthController::class, 'login'])->middleware(['throttle:login', SecurityAudit::class]);

    // Refresh SENGAJA di luar auth:api: token yang baru saja expired (masih dalam
    // refresh_ttl) harus tetap bisa ditukar — auth:api akan menolaknya lebih dulu.
    // Self-protected: butuh token bertanda tangan sah dalam window. Rate-limit anti abuse.
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');

    // "Me" sengaja di luar auth:api — endpoint ini harus MEMBEDAKAN 401 (token
    // bermasalah) dari 403 (akun dihapus/dinonaktifkan), padahal auth:api
    // menyatukan keduanya jadi 401. Penanganan token ada di dalam method-nya;
    // rate limit dipasang supaya polling kelayakan-akun per perangkat tak bisa
    // disalahgunakan sebagai banjir.
    Route::get('me', [AuthController::class, 'me'])->middleware('throttle:120,1');

    // Terproteksi — wajib menyertakan token JWT yang valid (guard "api").
    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);

        // Ganti sandi sendiri. Rate limit sendiri, lebih ketat dari sekadar
        // "sudah punya token": di baliknya ada Hash::check terhadap sandi lama,
        // jadi endpoint ini adalah oracle untuk menebak sandi milik pemegang
        // token yang dicuri — persis yang dilindungi throttle:login di atas.
        Route::post('password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:6,1');
    });
});

// Manajemen staff (F-iam-b) — owner-only. `role` sengaja dirangkai SETELAH
// `auth:api` karena middleware itu membaca user hasil autentikasi, bukan klaim.
Route::middleware(['auth:api', 'role:owner'])->group(function () {
    Route::get('staff', [StaffController::class, 'index']);
    Route::post('staff', [StaffController::class, 'store']);
    Route::put('staff/{id}', [StaffController::class, 'update']);
    Route::delete('staff/{id}', [StaffController::class, 'destroy']);

    // Jejak audit keamanan — owner melihat siapa yang mencoba masuk, kapan,
    // dari IP mana, dan apakah throttle sempat berbunyi. Layar menyusul;
    // endpoint ini yang jadi sumbernya (terbaru dulu, 200 terakhir).
    //
    // PENTING (multi-tenant): peristiwa PRA-login (login_failed, throttle)
    // lahir sebelum tenant diketahui → tenant_id null dan terlihat semua
    // owner. Peristiwa pasca-login (register_ok) memakai tenant_id-nya.
    // Filter di sini menjaga yang ber-tenant tetap milik penyewanya.
    Route::get('security/events', function (\Illuminate\Http\Request $request) {
        $tenant = auth('api')->user()?->tenant_id;
        $q = \Illuminate\Support\Facades\DB::table('security_events');
        if ($tenant !== null) {
            $q->where(fn ($w) => $w->whereNull('tenant_id')->orWhere('tenant_id', $tenant));
        }
        $events = $q->orderByDesc('created_at')
            ->limit((int) $request->input('limit', 50))
            ->get();

        return response()->json(['data' => $events]);
    })->middleware('throttle:60,1');
});
