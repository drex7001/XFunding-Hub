<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        Paginator::defaultView('components.pagination');
        // PostgreSQL rejects non-numeric or oversized values for bigint keys with a query error.
        foreach (['bank', 'membership', 'accessRequest', 'approval', 'account'] as $parameter) {
            Route::pattern($parameter, '[0-9]{1,18}');
        }
        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(Str::lower(is_string($email) ? $email : '').'|'.$request->ip());
        });
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('payment-notifications', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
