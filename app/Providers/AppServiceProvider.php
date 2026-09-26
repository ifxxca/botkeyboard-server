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
        RateLimiter::for('attention-preauth', fn (Request $request): Limit => Limit::perMinute(1200)->by($request->ip()));
        RateLimiter::for('attention-client', fn (Request $request): Limit => Limit::perMinute((int) config('attention.client_checks_per_minute'))
            ->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('client-heartbeat', fn (Request $request): Limit => Limit::perMinute(12)
            ->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('admin-login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
