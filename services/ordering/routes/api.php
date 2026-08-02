<?php

use App\Http\Controllers\CashierOrderController;
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
// Limiter sendiri, tidak menumpang 'orders': limiter itu membatasi per qr_token,
// yang tak ada di sini — semua klaim akan tertumpuk di satu ember dan pelanggan
// ke-11 di seluruh kafe ikut ditolak. Juga tidak menumpang grup GET 60/mnt di
// atas: ketukan pertama MENULIS (menggeser tenggat), jadi ambangnya layak lebih
// ketat daripada polling status yang cuma membaca.
Route::post('orders/{id}/claim-paid', [OrderController::class, 'claimPaid'])
    ->middleware('throttle:claim');

// Endpoint kasir — antrean & keputusan uang. role cashier ATAU owner (owner
// boleh melakukan semua yang kasir bisa). Outlet diambil dari klaim token.
Route::middleware(['jwt', 'role:cashier,owner'])->group(function () {
    Route::get('cashier/orders', [CashierOrderController::class, 'index']);
    Route::get('cashier/orders/{id}', [CashierOrderController::class, 'show']);
    Route::post('cashier/orders/{id}/confirm-payment', [CashierOrderController::class, 'confirmPayment']);
    Route::post('cashier/orders/{id}/cancel', [CashierOrderController::class, 'cancel']);
    // Dipakai kasir sekarang, layar dapur (KDS) nanti — satu pintu, bukan dua
    // jalur yang harus sama-sama benar.
    Route::post('cashier/orders/{id}/ready', [CashierOrderController::class, 'markReady']);

    // Dibaca kasir, bukan cuma owner: identitas outlet di sini tercetak di
    // kepala struk, dan yang mencetak struk adalah kasir. Yang MENGUBAH tetap
    // owner saja (PUT & unggah QRIS di grup bawah).
    Route::get('settings', [SettingController::class, 'show']);
});

// Manajemen meja & tarif — owner saja (JWT terverifikasi + role:owner).
// Outlet diambil dari klaim token; akun tanpa outlet ditolak 403 di base Controller.
Route::middleware(['jwt', 'role:owner'])->group(function () {
    Route::get('tables', [TableController::class, 'index']);
    Route::post('tables', [TableController::class, 'store']);
    Route::put('tables/{id}', [TableController::class, 'update']);
    Route::delete('tables/{id}', [TableController::class, 'destroy']);
    Route::post('tables/{id}/rotate-qr', [TableController::class, 'rotateQr']);

    Route::put('settings', [SettingController::class, 'update']);
    // Unggah gambar QRIS. Endpoint sendiri, bukan menumpang PUT settings:
    // yang satu multipart berisi berkas, yang lain JSON berisi angka tarif —
    // menggabungkannya memaksa kedua jalur saling menanggung aturan yang tak
    // relevan bagi mereka.
    Route::post('settings/qris', [SettingController::class, 'uploadQris']);
});
