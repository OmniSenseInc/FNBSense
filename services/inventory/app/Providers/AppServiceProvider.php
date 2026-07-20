<?php

namespace App\Providers;

use App\Messaging\EventPublisher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Satu koneksi broker dipakai bersama consumer & command. Tanpa singleton,
        // tiap resolve membuka koneksi baru (bocor) dan close() di InventoryConsume
        // menutup instance yang bukan dipakai consumer.
        $this->app->singleton(EventPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
