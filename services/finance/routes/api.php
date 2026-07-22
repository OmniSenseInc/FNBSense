<?php

use App\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'finance', 'status' => 'ok']));

// Shift kasir + laporan per-shift (F5c). Scope tenant+outlet dari JWT.
Route::middleware(['jwt', 'role:cashier,owner'])->group(function () {
    Route::post('shifts/open', [ShiftController::class, 'open']);
    Route::post('shifts/{id}/close', [ShiftController::class, 'close']);
    Route::get('shifts/{id}', [ShiftController::class, 'show']);
});

// F5d menambah rute expense/laporan lintas-shift di sini.
