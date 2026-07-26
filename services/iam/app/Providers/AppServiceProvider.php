<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // Kebijakan password: min 8 + huruf besar-kecil + angka. uncompromised()
        // sengaja TIDAK dipakai (butuh API HIBP eksternal -> registrasi lambat &
        // test flaky). Berlaku ke semua rule Password::defaults() (RegisterRequest).
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers());

        // Login dibatasi per IP DAN per email (skrutini auth 2026-07-26).
        // Batas per-IP saja tak menahan penyerang yang memutar IP terhadap SATU
        // akun target; batas per-email menutup celah itu. Melewati salah satu
        // ambang sudah cukup untuk 429. Pola sama dengan limiter 'orders' di Ordering.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(6)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
            ];
        });

        // Pengaman keamanan: di production, paksa debug mati agar detail
        // error / stack trace tidak pernah bocor ke klien meskipun
        // APP_DEBUG keliru di-set true pada .env production.
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }
    }
}
