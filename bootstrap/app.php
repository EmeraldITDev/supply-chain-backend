<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply CORS to all requests (global, before other middleware)
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        
        // Track user activity for automatic logout after 5 minutes of inactivity
        $middleware->prepend(\App\Http\Middleware\TrackUserActivity::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
        
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withSchedule(function ($schedule) {
        // Run document expiry check daily at midnight
        $schedule->command('documents:mark-expired')->dailyAt('00:00')
            ->name('Update Expired Vendor Documents')
            ->description('Mark vendor registration documents as expired');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON responses for API errors
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->expectsJson() || str_starts_with($request->path(), 'api/');
        });
    })->create();
