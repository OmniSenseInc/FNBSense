<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Publik — dibatasi rate limit (6 request/menit per IP) untuk membendung
    // brute-force password & spam registrasi.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Refresh SENGAJA di luar auth:api: token yang baru saja expired (masih dalam
    // refresh_ttl) harus tetap bisa ditukar — auth:api akan menolaknya lebih dulu.
    // Self-protected: butuh token bertanda tangan sah dalam window. Rate-limit anti abuse.
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');

    // Terproteksi — wajib menyertakan token JWT yang valid (guard "api").
    Route::middleware('auth:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// Manajemen staff (F-iam-b) — owner-only. `role` sengaja dirangkai SETELAH
// `auth:api` karena middleware itu membaca user hasil autentikasi, bukan klaim.
Route::middleware(['auth:api', 'role:owner'])->group(function () {
    Route::get('staff', [StaffController::class, 'index']);
    Route::post('staff', [StaffController::class, 'store']);
    Route::put('staff/{id}', [StaffController::class, 'update']);
});
