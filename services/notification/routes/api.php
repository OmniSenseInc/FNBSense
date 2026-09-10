<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('ping', fn () => response()->json(['service' => 'notification', 'status' => 'ok']));

// Inbox notifikasi — kasir, owner, dan manager sama-sama menerima; scope tenant+outlet dari JWT.
Route::middleware(['jwt', 'role:cashier,owner,manager'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
});
