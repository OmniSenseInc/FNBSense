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

        // POST /api/orders/{id}/claim-paid, juga publik. Dibatasi per IP DAN per
        // pesanan: yang pertama menahan orang yang menembaki banyak id sekaligus,
        // yang kedua menahan ketukan berulang pada satu pesanan. Ambang per
        // pesanan boleh rendah — sesudah klaim pertama, ketukan berikutnya tak
        // mengubah apa pun dan cuma memakan lock baris.
        RateLimiter::for('claim', function (Request $request) {
            return [
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('order:'.(string) $request->route('id')),
            ];
        });
    }
}
