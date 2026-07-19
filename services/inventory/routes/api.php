<?php

use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'inventory', 'status' => 'ok']));

// Saldo stok: owner-only (restock + opname manual). Scope tenant+outlet dari JWT.
Route::middleware(['jwt', 'role:owner'])->group(function () {
    Route::get('stock', [StockController::class, 'index']);
    Route::post('stock/restock', [StockController::class, 'restock']);
    Route::post('stock/adjust', [StockController::class, 'adjust']);
});
