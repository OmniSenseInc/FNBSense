<?php

namespace App\Providers;

use App\Auth\IamUserProvider;
use App\Models\DashboardUser;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        Auth::provider('iam', fn () => new IamUserProvider);

        Event::listen(Logout::class, function (Logout $event): void {
            if (! $event->user instanceof DashboardUser) {
                return;
            }

            $token = session('dashboard.jwt');

            if (is_string($token) && $token !== '') {
                try {
                    Http::baseUrl((string) config('services.iam.url'))
                        ->acceptJson()
                        ->withToken($token)
                        ->timeout(5)
                        ->post('/api/auth/logout');
                } catch (\Throwable $exception) {
                    Log::warning('dashboard.logout: IAM tidak tersedia.', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            session()->forget(['dashboard.jwt', 'dashboard.token_expires_at', 'dashboard.user']);
        });
    }
}
