<?php

namespace App\Providers;

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

        // Pengaman keamanan: di production, paksa debug mati agar detail
        // error / stack trace tidak pernah bocor ke klien meskipun
        // APP_DEBUG keliru di-set true pada .env production.
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }
    }
}
