<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

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
        Schema::defaultStringLength(191);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('device-activation', fn (Request $request) => [
            Limit::perMinute(5)->by('activation-ip:'.$request->ip()),
            Limit::perMinute(5)->by('activation-device:'.hash('sha256', (string) ($request->input('device_reference') ?: $request->input('device_uuid')))),
            Limit::perMinute(5)->by('activation-code:'.hash('sha256', strtoupper(trim((string) $request->input('activation_code'))))),
        ]);
    }
}
