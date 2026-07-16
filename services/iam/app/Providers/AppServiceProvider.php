<?php

namespace App\Providers;

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
        // Pengaman keamanan: di production, paksa debug mati agar detail
        // error / stack trace tidak pernah bocor ke klien meskipun
        // APP_DEBUG keliru di-set true pada .env production.
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }
    }
}
