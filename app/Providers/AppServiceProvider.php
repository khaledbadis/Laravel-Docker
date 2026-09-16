<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
            Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));
        }

        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('registration-ip', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
