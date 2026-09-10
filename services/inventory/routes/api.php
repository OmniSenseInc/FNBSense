<?php

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'inventory', 'status' => 'ok']));

// Lihat saldo stok: kasir & owner (kasir butuh tahu stok, tak boleh mengubah). RBAC.md.
// Gerbang stok untuk Ordering (service-to-service, auth X-Service-Token).
// Bentuk TUNGGAL `availability` menandai endpoint mesin, sepola `recipe` dan
// `ingredient` di Catalog. Tak ada JWT: yang memesan adalah pelanggan tanpa akun.
Route::post('availability', AvailabilityController::class)->middleware(['service', 'throttle:120,1']);

// Saldo mentah untuk Catalog (menandai produk habis di /api/menu). Dipisah dari
// `availability` supaya Catalog tak perlu memanggil gerbang yang justru
// memanggil balik Catalog untuk resepnya — lingkaran tiap pemindaian QR.
Route::get('balance', BalanceController::class)->middleware(['service', 'throttle:120,1']);

Route::middleware(['jwt', 'role:cashier,owner,manager'])->group(function () {
    Route::get('stock', [StockController::class, 'index']);
});

// Ubah stok: owner-only (restock + opname manual). Scope tenant+outlet dari JWT.
Route::middleware(['jwt', 'role:owner'])->group(function () {
    Route::post('stock/restock', [StockController::class, 'restock']);
    Route::post('stock/adjust', [StockController::class, 'adjust']);
});
