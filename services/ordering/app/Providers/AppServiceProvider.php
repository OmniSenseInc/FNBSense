<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // POST /api/orders publik: batasi per IP DAN per qr_token (skrutini #4).
        // Dua limit sekaligus -> satu orang tak bisa membanjiri antrean kasir,
        // baik dari satu IP maupun menembak satu meja berulang. Melewati salah
        // satu ambang sudah cukup untuk 429.
        RateLimiter::for('orders', function (Request $request) {
            return [
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                Limit::perMinute(10)->by('token:'.(string) $request->input('qr_token')),
            ];
        });
    }
}
