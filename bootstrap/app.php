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
        // Apply CORS to all requests (must be first to ensure headers are set on all responses)
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

        // Add middleware to ensure CORS headers are always sent
        $middleware->append(\App\Http\Middleware\EnsureCorsHeaders::class);
    })
    ->withSchedule(function ($schedule) {
        // Run document expiry check daily at midnight
        $schedule->command('documents:mark-expired')->dailyAt('00:00')
            ->name('Update Expired Vendor Documents')
            ->description('Mark vendor registration documents as expired');

        $schedule->command('fleet:check-vehicle-documents')->dailyAt('00:05')
            ->withoutOverlapping(10);

        $schedule->command('fleet:check-maintenance')->dailyAt('00:10')
            ->withoutOverlapping(10);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON responses for API errors
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->expectsJson() || str_starts_with($request->path(), 'api/');
        });

        // Handle connection timeout errors
        $exceptions->render(function (\Throwable $e, $request) {
            if (str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'Connection timeout') ||
                str_contains($e->getMessage(), 'Lost connection') ||
                $e instanceof \Illuminate\Database\QueryException) {

                $statusCode = 503;
                if ($e instanceof \Illuminate\Database\QueryException &&
                    str_contains($e->getMessage(), 'Connection refused')) {
                    $statusCode = 503; // Service Unavailable
                }

                return response()->json([
                    'success' => false,
                    'error' => 'Database connection error',
                    'code' => 'CONNECTION_ERROR',
                    'message' => 'The server is unable to process your request. Please try again in a few moments.',
                    'timestamp' => now()->toIso8601String(),
                ], $statusCode)->header('Connection', 'close');
            }

            // Handle validation errors
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $e->errors(),
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }

            // Handle authentication errors
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthenticated',
                    'code' => 'UNAUTHENTICATED',
                    'timestamp' => now()->toIso8601String(),
                ], 401);
            }

            // Handle authorization errors
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'code' => 'FORBIDDEN',
                    'timestamp' => now()->toIso8601String(),
                ], 403);
            }

            // Return null to let Laravel handle it with default behavior
            return null;
        });
    })->create();
