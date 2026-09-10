<?php

use App\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::get('ping', fn () => response()->json(['service' => 'reporting', 'status' => 'ok']));

Route::middleware(['jwt', 'role:owner,manager'])->group(function () {
    Route::get('summary', [AnalyticsController::class, 'summary']);
    Route::get('trends/daily', [AnalyticsController::class, 'dailyTrend']);
    Route::get('products/top', [AnalyticsController::class, 'topProducts']);
    Route::get('promotions/performance', [AnalyticsController::class, 'promotionPerformance']);
});
