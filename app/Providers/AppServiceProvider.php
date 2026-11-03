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
        // Share the budget across both public forms, including invalid input.
        // Twenty attempts per minute accommodates an office's shared address
        // while limiting repeated validation and password-hashing work on the Pi.
        RateLimiter::for('signup', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) $request->ip())
                ->response(fn (Request $request, array $headers) => response()->view('errors.429', [], 429, $headers));
        });
    }
}
