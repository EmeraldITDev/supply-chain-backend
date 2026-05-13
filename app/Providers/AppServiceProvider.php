<?php

namespace App\Providers;

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
        // Render (and most hosts) terminate TLS at the edge; APP_URL must match.
        // If APP_URL is still http:// while the SPA is https://, absolute URLs from
        // URL::to() (e.g. signature preview links) become mixed active content and are blocked.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
