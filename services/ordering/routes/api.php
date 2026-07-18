<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'ordering', 'status' => 'ok']));

// Endpoint customer — PUBLIK, tanpa login (tenant/outlet diturunkan dari qr_token).
// GET dibatasi 60/menit/IP; POST /api/orders pakai limiter gabungan 'orders'
// (IP 20/mnt + qr_token 10/mnt) supaya satu orang tak membanjiri antrean kasir.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('t/{qrToken}', [OrderController::class, 'showTable']);
    Route::get('orders/{id}', [OrderController::class, 'show']);
});
Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders');

// Manajemen meja & tarif — owner saja (JWT terverifikasi + role:owner).
// Outlet diambil dari klaim token; akun tanpa outlet ditolak 403 di base Controller.
Route::middleware(['jwt', 'role:owner'])->group(function () {
    Route::get('tables', [TableController::class, 'index']);
    Route::post('tables', [TableController::class, 'store']);
    Route::put('tables/{id}', [TableController::class, 'update']);
    Route::delete('tables/{id}', [TableController::class, 'destroy']);
    Route::post('tables/{id}/rotate-qr', [TableController::class, 'rotateQr']);

    Route::get('settings', [SettingController::class, 'show']);
    Route::put('settings', [SettingController::class, 'update']);
});
