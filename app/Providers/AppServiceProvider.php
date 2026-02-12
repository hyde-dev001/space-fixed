<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

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
        // In local/debug mode, exclude our development-only finance public endpoints
        // from CSRF verification so the front-end can POST without a session token.
        if (app()->environment('local') || config('app.debug')) {
            VerifyCsrfToken::except([
                'api/finance/public/*',
                '/api/finance/public/*',
                'api/finance/*', // broader dev convenience
            ]);
        }
    }
}
