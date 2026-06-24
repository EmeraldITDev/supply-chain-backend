<?php

namespace App\Providers;

use App\Models\Vendor;
use App\Observers\VendorObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        Vendor::observe(VendorObserver::class);

        // Render (and most hosts) terminate TLS at the edge; APP_URL must match.
        // If APP_URL is still http:// while the SPA is https://, absolute URLs from
        // URL::to() (e.g. signature preview links) become mixed active content and are blocked.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Some proxies / SPA clients omit or rename Authorization; accept common alternates for Sanctum.
        Sanctum::$accessTokenRetrievalCallback = static function ($request) {
            if ($token = $request->bearerToken()) {
                return $token;
            }
            foreach (['X-Auth-Token', 'X-Access-Token'] as $header) {
                $value = $request->header($header);
                if (is_string($value) && $value !== '') {
                    return trim($value);
                }
            }

            return null;
        };
    }
}
