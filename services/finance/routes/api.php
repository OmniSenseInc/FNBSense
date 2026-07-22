<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'finance', 'status' => 'ok']));

// Shift kasir + laporan per-shift (F5c). Scope tenant+outlet dari JWT.
Route::middleware(['jwt', 'role:cashier,owner'])->group(function () {
    Route::post('shifts/open', [ShiftController::class, 'open']);
    Route::post('shifts/{id}/close', [ShiftController::class, 'close']);
    Route::get('shifts/{id}', [ShiftController::class, 'show']);

    // Pengeluaran (F5d): kasir di kasir yang belanja → boleh catat & lihat.
    Route::post('expenses', [ExpenseController::class, 'store']);
    Route::get('expenses', [ExpenseController::class, 'index']);
});

// Laporan laba-rugi lintas hari = alat OWNER, bukan kasir. Kasir cukup laporan
// per-shift-nya sendiri (F5c). Pisahkan role biar akun kasir tak bisa tarik P&L
// & rincian belanja seluruh riwayat outlet.
Route::middleware(['jwt', 'role:owner'])->group(function () {
    Route::get('reports', [ReportController::class, 'summary']);
});
