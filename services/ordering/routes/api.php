<?php

use App\Http\Controllers\SettingController;
use App\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'ordering', 'status' => 'ok']));

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
