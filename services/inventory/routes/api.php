<?php

use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'inventory', 'status' => 'ok']));

// Lihat saldo stok: kasir & owner (kasir butuh tahu stok, tak boleh mengubah). RBAC.md.
Route::middleware(['jwt', 'role:cashier,owner'])->group(function () {
    Route::get('stock', [StockController::class, 'index']);
});

// Ubah stok: owner-only (restock + opname manual). Scope tenant+outlet dari JWT.
Route::middleware(['jwt', 'role:owner'])->group(function () {
    Route::post('stock/restock', [StockController::class, 'restock']);
    Route::post('stock/adjust', [StockController::class, 'adjust']);
});
